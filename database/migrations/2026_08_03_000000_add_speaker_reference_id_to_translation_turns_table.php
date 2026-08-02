<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translation_turns', function (Blueprint $table) {
            $table->string('speaker_reference_id', 128)
                ->nullable()
                ->after('target_language');
        });
    }

    public function down(): void
    {
        Schema::table('translation_turns', function (Blueprint $table) {
            $table->dropColumn('speaker_reference_id');
        });
    }
};
