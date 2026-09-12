<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_profile_id')->index()->constrained()->restrictOnDelete();
            $table->string('score_type', 30)->index();
            $table->string('subject_code', 30)->nullable();
            $table->string('subject_name', 100)->nullable();
            $table->decimal('score', 8, 3);
            $table->unsignedSmallInteger('exam_year')->index();
            $table->boolean('verified')->default(false)->index();
            $table->foreignId('verified_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_scores');
    }
};
