<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'access_code_id',
    'reference',
    'original_name',
    'stored_path',
    'mime_type',
    'total_size',
    'chunk_size',
    'total_chunks',
    'received_indexes',
    'sender_name',
    'sender_email',
    'message',
    'status',
])]
class FileUpload extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_indexes' => 'array',
            'total_size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
        ];
    }
}
