<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_major_offerings', function (Blueprint $table): void {
            $table->unique(['id', 'admission_round_id', 'major_id'], 'offering_quota_reference');
        });
        Schema::create('admission_quota_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('candidate_major_offering_id');
            $table->unsignedBigInteger('admission_round_id');
            $table->unsignedBigInteger('major_id');
            $table->unsignedInteger('version');
            $table->unsignedInteger('total_quota')->default(0);
            $table->enum('status', ['draft', 'approved', 'retired'])->default('draft');
            $table->char('catalog_fingerprint', 64);
            $table->unsignedBigInteger('previous_version_id')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('retired_at')->nullable();
            $table->text('reason');
            $table->char('content_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['candidate_major_offering_id', 'version'], 'quota_offering_version_unique');
            $table->unique(['id', 'candidate_major_offering_id'], 'quota_previous_reference');
            $table->unique(['id', 'admission_round_id', 'major_id'], 'quota_program_reference');
            $table->index(['candidate_major_offering_id', 'status'], 'quota_offering_status_index');
            $table->foreign(['candidate_major_offering_id', 'admission_round_id', 'major_id'], 'quota_offering_foreign')->references(['id', 'admission_round_id', 'major_id'])->on('candidate_major_offerings')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['previous_version_id', 'candidate_major_offering_id'], 'quota_previous_foreign')->references(['id', 'candidate_major_offering_id'])->on('admission_quota_versions')->restrictOnDelete()->restrictOnUpdate();
        });
        Schema::create('admission_quota_method_limits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admission_quota_version_id');
            $table->unsignedBigInteger('admission_program_id');
            $table->unsignedBigInteger('admission_round_id');
            $table->unsignedBigInteger('major_id');
            $table->unsignedInteger('quota')->nullable();
            $table->timestamps();
            $table->unique(['admission_quota_version_id', 'admission_program_id'], 'quota_version_program_unique');
            $table->foreign(['admission_quota_version_id', 'admission_round_id', 'major_id'], 'quota_limit_version_foreign')->references(['id', 'admission_round_id', 'major_id'])->on('admission_quota_versions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['admission_program_id', 'admission_round_id', 'major_id'], 'quota_limit_program_foreign')->references(['id', 'admission_round_id', 'major_id'])->on('admission_programs')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_quota_method_limits');
        Schema::dropIfExists('admission_quota_versions');
        Schema::table('candidate_major_offerings', function (Blueprint $table): void {
            $table->dropUnique('offering_quota_reference');
        });
    }
};
