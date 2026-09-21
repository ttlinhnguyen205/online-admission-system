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
        Schema::create('candidate_transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_profile_id')->constrained()->restrictOnDelete();
            $table->string('school_name', 255)->nullable();
            $table->unsignedSmallInteger('graduation_year');
            $table->string('evidence_path', 500)->nullable();
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->foreignId('verified_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['candidate_profile_id', 'graduation_year'], 'candidate_transcripts_lookup_index');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_transcripts');
    }
};
