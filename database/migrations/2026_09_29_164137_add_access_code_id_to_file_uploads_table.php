<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_uploads', function (Blueprint $table) {
            $table->foreignUuid('access_code_id')
                ->nullable()
                ->constrained('access_codes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('file_uploads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_code_id');
        });
    }
};
