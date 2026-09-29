<?php

namespace Tests\Feature;

use App\Models\AccessCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        Storage::fake('chunks');
        config(['uploads.chunk_bytes' => 1024, 'uploads.max_bytes' => 10 * 1024 * 1024]);

        $this->withSession(['upload_access_code_id' => AccessCode::issue('Test', null)->id]);
    }

    public function test_homepage_is_available(): void
    {
        $this->get('/')->assertOk()->assertSee('Dateien senden', false);
    }

    public function test_visitor_can_upload_a_file_in_chunks(): void
    {
        $payload = str_repeat('A', 2500);
        $upload = $this->startUpload('entwurf.mp4', strlen($payload));
        $this->sendChunks($upload, $payload);

        $response = $this->postJson('/api/uploads/'.$upload['id'].'/complete');

        $response->assertOk()
            ->assertJsonPath('original_name', 'entwurf.mp4')
            ->assertJsonPath('size', 2500);

        $reference = $response->json('reference');
        $stored = collect(Storage::disk('uploads')->allFiles())
            ->first(fn (string $path) => str_ends_with($path, '.mp4'));

        $this->assertNotNull($stored);
        $this->assertSame($payload, Storage::disk('uploads')->get($stored));
        $this->assertTrue(Storage::disk('uploads')->exists(dirname($stored).'/'.$reference.'.json'));
        $this->assertSame([], Storage::disk('chunks')->allFiles());

        $meta = json_decode(Storage::disk('uploads')->get(dirname($stored).'/'.$reference.'.json'), true);
        $this->assertSame('Ada Lovelace', $meta['sender_name']);
        $this->assertSame('ada@example.com', $meta['sender_email']);
    }

    public function test_upload_with_more_than_sixty_chunks_can_be_completed(): void
    {
        $payload = str_repeat('A', 65 * 1024);
        $upload = $this->startUpload('viele-teile.zip', strlen($payload));
        $this->sendChunks($upload, $payload);

        $response = $this->postJson('/api/uploads/'.$upload['id'].'/complete');

        $response->assertOk()
            ->assertJsonPath('original_name', 'viele-teile.zip')
            ->assertJsonPath('size', 65 * 1024);
        $this->assertDatabaseHas('file_uploads', [
            'id' => $upload['id'],
            'status' => 'complete',
        ]);
    }

    public function test_incomplete_upload_cannot_be_finished(): void
    {
        $upload = $this->startUpload('halb.bin', 2048);

        $this->post('/api/uploads/'.$upload['id'].'/chunks', [
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('teil', str_repeat('B', $upload['chunk_size'])),
        ])->assertOk();

        $this->postJson('/api/uploads/'.$upload['id'].'/complete')
            ->assertStatus(422)
            ->assertJsonValidationErrors('upload');
    }

    public function test_short_chunk_is_rejected(): void
    {
        $upload = $this->startUpload('kurz.bin', 2048);

        $this->post('/api/uploads/'.$upload['id'].'/chunks', [
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('teil', 'zu-kurz'),
        ])->assertStatus(422)->assertJsonValidationErrors('chunk');
    }

    public function test_executable_files_are_rejected(): void
    {
        $this->postJson('/api/uploads', $this->sender([
            'original_name' => 'setup.exe',
            'total_size' => 1200,
        ]))->assertStatus(422)->assertJsonValidationErrors('original_name');
    }

    public function test_oversized_files_are_rejected(): void
    {
        config(['uploads.max_bytes' => 100]);

        $this->postJson('/api/uploads', $this->sender([
            'original_name' => 'riesig.zip',
            'total_size' => 101,
        ]))->assertStatus(422)->assertJsonValidationErrors('total_size');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sender(array $overrides = []): array
    {
        return array_merge([
            'original_name' => 'datei.bin',
            'total_size' => 10,
            'sender_name' => 'Ada Lovelace',
            'sender_email' => 'ada@example.com',
            'message' => 'Bitte im Projektordner ablegen.',
        ], $overrides);
    }

    /**
     * @return array{id: string, chunk_size: int, total_chunks: int}
     */
    private function startUpload(string $name, int $size): array
    {
        $response = $this->postJson('/api/uploads', $this->sender([
            'original_name' => $name,
            'total_size' => $size,
        ]));

        $response->assertCreated();

        return $response->json();
    }

    /**
     * @param  array{id: string, chunk_size: int, total_chunks: int}  $upload
     */
    private function sendChunks(array $upload, string $payload): void
    {
        $parts = str_split($payload, $upload['chunk_size']);

        foreach ($parts as $index => $part) {
            $this->post('/api/uploads/'.$upload['id'].'/chunks', [
                'index' => $index,
                'chunk' => UploadedFile::fake()->createWithContent('teil-'.$index, $part),
            ])->assertOk();
        }
    }
}
