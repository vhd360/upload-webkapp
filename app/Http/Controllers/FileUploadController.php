<?php

namespace App\Http\Controllers;

use App\Models\AccessCode;
use App\Models\FileUpload;
use App\Services\ChunkedUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FileUploadController extends Controller
{
    public function __construct(private ChunkedUploadService $uploads) {}

    public function store(Request $request): JsonResponse
    {
        $maxBytes = (int) config('uploads.max_bytes');

        $data = $request->validate([
            'original_name' => ['required', 'string', 'max:255'],
            'total_size' => ['required', 'integer', 'min:1', 'max:'.$maxBytes],
            'mime_type' => ['nullable', 'string', 'max:150'],
            'sender_name' => ['required', 'string', 'max:120'],
            'sender_email' => ['required', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [
            'original_name.required' => 'Der Dateiname fehlt.',
            'total_size.max' => 'Die Datei ist größer als erlaubt.',
            'total_size.min' => 'Die Datei ist leer.',
            'sender_name.required' => 'Bitte geben Sie Ihren Namen an.',
            'sender_email.required' => 'Bitte geben Sie Ihre E-Mail-Adresse an.',
            'sender_email.email' => 'Bitte geben Sie eine gültige E-Mail-Adresse an.',
        ]);

        $data['access_code_id'] = $this->accessCode($request)->id;

        $upload = $this->uploads->start($data);

        return response()->json([
            'id' => $upload->id,
            'reference' => $upload->reference,
            'chunk_size' => $upload->chunk_size,
            'total_chunks' => $upload->total_chunks,
        ], 201);
    }

    public function chunk(Request $request, FileUpload $upload): JsonResponse
    {
        $this->ensureUploadBelongsToCode($request, $upload);

        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
        ], [
            'chunk.required' => 'Der Dateiteil fehlt.',
            'chunk.uploaded' => 'Dieser Dateiteil konnte nicht angenommen werden. Das PHP-Upload-Limit ist zu niedrig.',
        ]);

        $progress = $this->uploads->storeChunk($upload, (int) $data['index'], $request->file('chunk'));

        return response()->json($progress);
    }

    public function complete(Request $request, FileUpload $upload): JsonResponse
    {
        $this->ensureUploadBelongsToCode($request, $upload);

        $upload = $this->uploads->finish($upload);

        return response()->json([
            'id' => $upload->id,
            'reference' => $upload->reference,
            'original_name' => $upload->original_name,
            'size' => $upload->total_size,
        ]);
    }

    private function accessCode(Request $request): AccessCode
    {
        return $request->attributes->get('accessCode');
    }

    private function ensureUploadBelongsToCode(Request $request, FileUpload $upload): void
    {
        abort_if($upload->access_code_id !== $this->accessCode($request)->id, 403, 'Dieser Upload gehört zu einem anderen Zugangscode.');
    }
}
