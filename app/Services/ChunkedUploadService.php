<?php

namespace App\Services;

use App\Models\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChunkedUploadService
{
    /**
     * @var list<string>
     */
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'dll',
        'js', 'mjs', 'vbs', 'ps1', 'sh', 'bash', 'hta',
        'htaccess', 'htpasswd',
    ];

    /**
     * @param  array{original_name: string, total_size: int, mime_type?: string|null, sender_name: string, sender_email: string, message?: string|null}  $data
     */
    public function start(array $data): FileUpload
    {
        $this->assertSafeFilename($data['original_name']);

        $chunkSize = $this->chunkSize();
        $totalChunks = (int) ceil($data['total_size'] / $chunkSize);

        return FileUpload::query()->create([
            'access_code_id' => $data['access_code_id'] ?? null,
            'reference' => $this->makeReference(),
            'original_name' => $data['original_name'],
            'mime_type' => $data['mime_type'] ?? null,
            'total_size' => $data['total_size'],
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
            'received_indexes' => [],
            'sender_name' => $data['sender_name'],
            'sender_email' => $data['sender_email'],
            'message' => $data['message'] ?? null,
            'status' => 'pending',
        ]);
    }

    /**
     * @return array{received: int, total_chunks: int}
     */
    public function storeChunk(FileUpload $upload, int $index, UploadedFile $chunk): array
    {
        $upload->refresh();

        if ($upload->status === 'complete') {
            throw ValidationException::withMessages([
                'chunk' => 'Diese Datei wurde bereits vollständig übertragen.',
            ]);
        }

        if ($index < 0 || $index >= $upload->total_chunks) {
            throw ValidationException::withMessages([
                'index' => 'Dieser Dateiteil gehört nicht zu der Übertragung.',
            ]);
        }

        $expected = $this->expectedChunkLength($upload, $index);

        if ($chunk->getSize() !== $expected) {
            throw ValidationException::withMessages([
                'chunk' => 'Dieser Dateiteil ist unvollständig.',
            ]);
        }

        $chunk->storeAs($upload->id, $index.'.part', 'chunks');

        $indexes = $upload->received_indexes ?? [];

        if (! in_array($index, $indexes, true)) {
            $indexes[] = $index;
            sort($indexes);
            $upload->received_indexes = $indexes;
            $upload->save();
        }

        return [
            'received' => count($upload->received_indexes ?? []),
            'total_chunks' => $upload->total_chunks,
        ];
    }

    public function finish(FileUpload $upload): FileUpload
    {
        $lock = Cache::lock('upload-finish-'.$upload->id, 7200);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'upload' => 'Die Datei wird gerade zusammengesetzt.',
            ]);
        }

        try {
            $upload->refresh();

            if ($upload->status === 'complete') {
                return $upload;
            }

            $this->assertAllChunksPresent($upload);

            $relative = $this->assemble($upload);
            $this->writeMetadata($upload, $relative);

            $upload->forceFill([
                'status' => 'complete',
                'stored_path' => $relative,
            ])->save();

            Storage::disk('chunks')->deleteDirectory($upload->id);

            return $upload;
        } finally {
            $lock->release();
        }
    }

    public function chunkSize(): int
    {
        $configured = max(1, (int) config('uploads.chunk_bytes'));
        $uploadLimit = $this->iniToBytes((string) ini_get('upload_max_filesize'));
        $postLimit = $this->iniToBytes((string) ini_get('post_max_size'));
        $ceiling = min($uploadLimit, $postLimit);

        if ($ceiling <= 0 || $ceiling >= PHP_INT_MAX) {
            return $configured;
        }

        $headroom = 256 * 1024;
        $safe = $ceiling > $headroom ? $ceiling - $headroom : (int) floor($ceiling * 0.75);

        return max(1, min($configured, $safe));
    }

    public function iniToBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }

    private function assertSafeFilename(string $name): void
    {
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw ValidationException::withMessages([
                'original_name' => 'Der Dateiname ist ungültig.',
            ]);
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'original_name' => 'Dieser Dateityp kann hier nicht hochgeladen werden.',
            ]);
        }
    }

    private function assertAllChunksPresent(FileUpload $upload): void
    {
        $indexes = $upload->received_indexes ?? [];

        for ($index = 0; $index < $upload->total_chunks; $index++) {
            $missingIndex = ! in_array($index, $indexes, true);
            $missingFile = ! Storage::disk('chunks')->exists($upload->id.'/'.$index.'.part');

            if ($missingIndex || $missingFile) {
                throw ValidationException::withMessages([
                    'upload' => 'Die Übertragung ist noch nicht vollständig.',
                ]);
            }
        }
    }

    private function expectedChunkLength(FileUpload $upload, int $index): int
    {
        $isLast = $index === $upload->total_chunks - 1;
        $lastLength = $upload->total_size - (($upload->total_chunks - 1) * $upload->chunk_size);

        return $isLast ? $lastLength : $upload->chunk_size;
    }

    private function assemble(FileUpload $upload): string
    {
        $storedName = $this->storedName($upload);
        $relative = now()->format('Y/m').'/'.$storedName;
        $partial = $relative.'.partial';
        $disk = Storage::disk('uploads');

        $disk->makeDirectory(dirname($relative));

        $output = fopen($disk->path($partial), 'wb');

        if ($output === false) {
            throw ValidationException::withMessages([
                'upload' => 'Die Datei konnte nicht gespeichert werden.',
            ]);
        }

        try {
            for ($index = 0; $index < $upload->total_chunks; $index++) {
                $inputPath = Storage::disk('chunks')->path($upload->id.'/'.$index.'.part');
                $input = fopen($inputPath, 'rb');

                if ($input === false) {
                    throw ValidationException::withMessages([
                        'upload' => 'Ein Dateiteil fehlt auf dem Server.',
                    ]);
                }

                stream_copy_to_stream($input, $output);
                fclose($input);
            }
        } catch (\Throwable $exception) {
            fclose($output);
            $disk->delete($partial);

            throw $exception;
        }

        fclose($output);

        $actualSize = filesize($disk->path($partial));

        if ($actualSize !== $upload->total_size) {
            $disk->delete($partial);

            throw ValidationException::withMessages([
                'upload' => 'Die gespeicherte Datei ist unvollständig.',
            ]);
        }

        if ($disk->exists($relative)) {
            $disk->delete($relative);
        }

        $disk->move($partial, $relative);

        return $relative;
    }

    private function writeMetadata(FileUpload $upload, string $relative): void
    {
        $meta = [
            'reference' => $upload->reference,
            'original_name' => $upload->original_name,
            'stored_name' => basename($relative),
            'size' => $upload->total_size,
            'mime_type' => $upload->mime_type,
            'sender_name' => $upload->sender_name,
            'sender_email' => $upload->sender_email,
            'message' => $upload->message,
            'completed_at' => now()->toIso8601String(),
        ];

        Storage::disk('uploads')->put(
            dirname($relative).'/'.$upload->reference.'.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
    }

    private function storedName(FileUpload $upload): string
    {
        $base = pathinfo($upload->original_name, PATHINFO_FILENAME);
        $slug = Str::limit(Str::slug($base), 80, '');
        $slug = $slug !== '' ? $slug : 'datei';
        $extension = strtolower(pathinfo($upload->original_name, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : '';

        return $upload->reference.'-'.$slug.($extension !== '' ? '.'.$extension : '');
    }

    private function makeReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = 'WK-';

            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (FileUpload::query()->where('reference', $code)->exists());

        return $code;
    }
}
