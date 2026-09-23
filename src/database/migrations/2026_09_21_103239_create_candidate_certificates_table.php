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
        Schema::create('candidate_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_profile_id')->constrained()->restrictOnDelete();
            $table->enum('certificate_type', ['ielts', 'toeic', 'sat']);
            $table->decimal('score', 8, 2);
            $table->string('certificate_number', 100)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('evidence_path', 500)->nullable();
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->foreignId('verified_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['candidate_profile_id', 'certificate_type'], 'candidate_certificates_lookup_index');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_certificates');
    }
};
