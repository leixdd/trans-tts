<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translation_turns', function (Blueprint $table) {
            $table->string('target_language', 16)
                ->default('ja')
                ->after('source_text');
        });
    }

    public function down(): void
    {
        Schema::table('translation_turns', function (Blueprint $table) {
            $table->dropColumn('target_language');
        });
    }
};
