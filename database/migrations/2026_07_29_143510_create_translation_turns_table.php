<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_turns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('visitor_id', 64)->index();
            $table->string('status', 32)->index();
            $table->text('source_text');
            $table->text('translation')->nullable();
            $table->longText('stream_debug')->nullable();
            $table->longText('worker_logs')->nullable();
            $table->string('audio_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['visitor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_turns');
    }
};
