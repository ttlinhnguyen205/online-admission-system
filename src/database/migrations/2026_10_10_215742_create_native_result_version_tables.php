<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('native_result_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admission_round_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft');
            $table->string('algorithm_version', 80);
            $table->text('policy_reference');
            $table->json('input_manifest');
            $table->char('input_hash', 64);
            $table->char('content_hash', 64);
            $table->timestamp('sealed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            foreach (['approved', 'published', 'rejected'] as $operation) {
                $table->foreignId($operation.'_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp($operation.'_at')->nullable();
            }
            $table->text('reason');
            $table->text('rejection_reason')->nullable();
            $table->unsignedTinyInteger('publication_slot')->nullable();
            $table->timestamps();
            $table->unique(['admission_round_id', 'version'], 'native_result_round_version_unique');
            $table->unique(['admission_round_id', 'content_hash'], 'native_result_round_hash_unique');
            $table->unique(['admission_round_id', 'publication_slot'], 'native_result_one_publication_unique');
            $table->index(['admission_round_id', 'status']);
        });
        Schema::create('native_result_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('native_result_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('submission_snapshot_id')->constrained('application_submission_snapshots')->restrictOnDelete();
            $table->foreignId('wish_method_binding_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('decision', 24);
            $table->decimal('score', 6, 3)->nullable();
            $table->json('payload');
            $table->text('reason');
            $table->timestamps();
            $table->unique(['native_result_version_id', 'application_id'], 'native_result_application_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('native_result_entries');
        Schema::dropIfExists('native_result_versions');
    }
};
