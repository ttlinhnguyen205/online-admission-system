<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table) {
            $table->string('citizen_id_front_path', 500)->nullable();
            $table->string('citizen_id_back_path', 500)->nullable();

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('verified_at')->nullable();

            $table->text('verification_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);

            $table->dropColumn([
                'citizen_id_front_path',
                'citizen_id_back_path',
                'verified_by',
                'verified_at',
                'verification_note',
            ]);
        });
    }
};