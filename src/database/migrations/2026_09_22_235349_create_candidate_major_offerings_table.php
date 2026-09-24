<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('admission_programs', function (Blueprint $table) {
            $table->unique(['id', 'admission_round_id', 'major_id'], 'program_round_major_reference');
        });

        Schema::create('candidate_major_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_round_id')->constrained()->restrictOnDelete();
            $table->foreignId('major_id')->constrained()->restrictOnDelete();
            $table->boolean('is_selectable')->default(false);
            $table->unsignedBigInteger('admission_program_id')->nullable();
            $table->unique(['admission_round_id', 'major_id'], 'candidate_offering_round_major_unique');
            $table->unique(['id', 'admission_program_id'], 'candidate_offering_program_reference');
            $table->index(['admission_program_id', 'admission_round_id', 'major_id'], 'candidate_offering_program_index');
            $table->foreign(['admission_program_id', 'admission_round_id', 'major_id'], 'candidate_offering_program_foreign')
                ->references(['id', 'admission_round_id', 'major_id'])->on('admission_programs')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_major_offerings');
        Schema::table('admission_programs', function (Blueprint $table) {
            $table->dropUnique('program_round_major_reference');
        });
    }
};
