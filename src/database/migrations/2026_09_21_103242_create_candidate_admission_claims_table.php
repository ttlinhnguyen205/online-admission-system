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
        Schema::create('candidate_admission_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_profile_id')->constrained()->restrictOnDelete();
            $table->string('claim_type', 100);
            $table->string('claim_code', 100)->nullable();
            $table->text('description')->nullable();
            $table->string('evidence_path', 500)->nullable();
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->foreignId('verified_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['candidate_profile_id', 'claim_type'], 'candidate_admission_claims_lookup_index');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_admission_claims');
    }
};
