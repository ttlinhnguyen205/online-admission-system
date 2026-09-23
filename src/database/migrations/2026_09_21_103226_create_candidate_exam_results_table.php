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
        Schema::create('candidate_exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_profile_id')->constrained()->restrictOnDelete();
            $table->enum('exam_type', ['thpt', 'dgnl', 'dgtd', 'vsat', 'spt']);
            $table->unsignedSmallInteger('exam_year');
            $table->date('exam_date')->nullable();
            $table->string('exam_session', 100)->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->decimal('overall_score', 8, 0)->nullable();
            $table->string('evidence_path', 500)->nullable();
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->foreignId('verified_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['candidate_profile_id', 'exam_type', 'exam_year'], 'candidate_exam_results_lookup_index');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_exam_results');
    }
};
