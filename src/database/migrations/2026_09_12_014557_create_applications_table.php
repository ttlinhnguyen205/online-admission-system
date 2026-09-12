<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_code', 40)->unique();
            $table->foreignId('candidate_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('admission_round_id')->index()->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('draft')->index();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('revision_reason')->nullable();
            $table->unique(['candidate_profile_id', 'admission_round_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
