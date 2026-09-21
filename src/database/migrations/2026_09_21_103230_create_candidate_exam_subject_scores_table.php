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
        Schema::create('candidate_exam_subject_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_exam_result_id')->constrained()->cascadeOnDelete();
            $table->string('subject_code', 30);
            $table->string('subject_name', 100);
            $table->decimal('score', 8, 3);
            $table->timestamps();

            $table->unique(['candidate_exam_result_id', 'subject_code'], 'candidate_exam_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_exam_subject_scores');
    }
};
