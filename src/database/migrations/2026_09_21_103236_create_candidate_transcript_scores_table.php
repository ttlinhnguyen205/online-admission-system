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
        Schema::create('candidate_transcript_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_transcript_id')->constrained()->cascadeOnDelete();
            $table->string('subject_code', 30);
            $table->string('subject_name', 100);
            $table->enum('grade_level', ['10', '11', '12']);
            $table->decimal('score', 8, 2);
            $table->timestamps();

            $table->unique(['candidate_transcript_id', 'subject_code', 'grade_level'], 'candidate_transcript_subject_grade_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_transcript_scores');
    }
};
