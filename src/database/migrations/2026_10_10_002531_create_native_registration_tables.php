<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->string('registration_mode', 16)->default('legacy')->index();
            $table->unique(['id', 'admission_round_id'], 'applications_id_round_unique');
        });
        Schema::create('evaluation_rule_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admission_method_id')->constrained()->restrictOnDelete();
            $table->string('template_identifier');
            $table->unsignedInteger('template_version');
            $table->unsignedInteger('version');
            $table->json('payload');
            $table->string('ranking_contract');
            $table->unsignedInteger('ranking_contract_version');
            $table->string('status', 16)->default('draft')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->char('content_hash', 64);
            $table->timestamps();
            $table->unique(['admission_method_id', 'version'], 'rule_method_version_unique');
            $table->unique(['id', 'admission_method_id'], 'rule_id_method_unique');
        });
        Schema::table('admission_programs', function (Blueprint $table): void {
            $table->unsignedBigInteger('evaluation_rule_version_id')->nullable();
            $table->unique(['id', 'admission_round_id', 'major_id', 'admission_method_id'], 'program_binding_scope_unique');
            $table->foreign(['evaluation_rule_version_id', 'admission_method_id'], 'program_rule_method_fk')->references(['id', 'admission_method_id'])->on('evaluation_rule_versions')->restrictOnDelete();
        });
        Schema::create('native_admission_wishes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('admission_round_id');
            $table->unsignedBigInteger('candidate_major_offering_id');
            $table->unsignedBigInteger('major_id');
            $table->unsignedSmallInteger('priority');
            $table->timestamps();
            $table->unique(['application_id', 'candidate_major_offering_id'], 'native_wish_application_offering_unique');
            $table->unique(['application_id', 'priority'], 'native_wish_application_priority_unique');
            $table->foreign(['application_id', 'admission_round_id'], 'native_wish_application_round_fk')->references(['id', 'admission_round_id'])->on('applications')->restrictOnDelete();
            $table->foreign(['candidate_major_offering_id', 'admission_round_id', 'major_id'], 'native_wish_offering_scope_fk')->references(['id', 'admission_round_id', 'major_id'])->on('candidate_major_offerings')->restrictOnDelete();
        });
        Schema::create('application_submission_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('submission_version');
            $table->timestamp('submitted_at');
            $table->timestamp('sealed_at')->nullable();
            $table->string('registration_mode', 16);
            $table->string('readiness', 32);
            $table->char('catalog_fingerprint', 64);
            $table->json('manifest');
            $table->char('content_hash', 64);
            $table->json('amendment_metadata')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'submission_version'], 'submission_application_version_unique');
            $table->unique(['id', 'application_id'], 'submission_id_application_unique');
        });
        Schema::create('submission_wish_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('submission_snapshot_id');
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('candidate_major_offering_id');
            $table->unsignedBigInteger('admission_round_id');
            $table->unsignedBigInteger('major_id');
            $table->unsignedSmallInteger('priority');
            $table->foreignId('live_wish_id')->nullable()->constrained('native_admission_wishes')->nullOnDelete();
            $table->json('payload');
            $table->char('content_hash', 64);
            $table->timestamps();
            $table->unique(['submission_snapshot_id', 'priority'], 'entry_snapshot_priority_unique');
            $table->unique(['submission_snapshot_id', 'candidate_major_offering_id'], 'entry_snapshot_offering_unique');
            $table->unique(['id', 'admission_round_id', 'major_id'], 'entry_binding_scope_unique');
            $table->foreign(['submission_snapshot_id', 'application_id'], 'entry_snapshot_owner_fk')->references(['id', 'application_id'])->on('application_submission_snapshots')->restrictOnDelete();
            $table->foreign(['application_id', 'admission_round_id'], 'entry_application_round_fk')->references(['id', 'admission_round_id'])->on('applications')->restrictOnDelete();
            $table->foreign(['candidate_major_offering_id', 'admission_round_id', 'major_id'], 'entry_offering_scope_fk')->references(['id', 'admission_round_id', 'major_id'])->on('candidate_major_offerings')->restrictOnDelete();
        });
        Schema::create('wish_method_bindings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('submission_wish_entry_id');
            $table->unsignedBigInteger('admission_round_id');
            $table->unsignedBigInteger('major_id');
            $table->unsignedBigInteger('admission_program_id');
            $table->unsignedBigInteger('admission_method_id');
            $table->unsignedBigInteger('evaluation_rule_version_id');
            $table->json('catalog_reference');
            $table->char('binding_hash', 64);
            $table->timestamps();
            $table->unique(['submission_wish_entry_id', 'admission_method_id'], 'binding_entry_method_unique');
            $table->foreign(['submission_wish_entry_id', 'admission_round_id', 'major_id'], 'binding_entry_scope_fk')->references(['id', 'admission_round_id', 'major_id'])->on('submission_wish_entries')->restrictOnDelete();
            $table->foreign(['admission_program_id', 'admission_round_id', 'major_id', 'admission_method_id'], 'binding_program_scope_fk')->references(['id', 'admission_round_id', 'major_id', 'admission_method_id'])->on('admission_programs')->restrictOnDelete();
            $table->foreign(['evaluation_rule_version_id', 'admission_method_id'], 'binding_rule_method_fk')->references(['id', 'admission_method_id'])->on('evaluation_rule_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wish_method_bindings');
        Schema::dropIfExists('submission_wish_entries');
        Schema::dropIfExists('application_submission_snapshots');
        Schema::dropIfExists('native_admission_wishes');
        Schema::table('admission_programs', function (Blueprint $table): void {
            $table->dropForeign('program_rule_method_fk');
            $table->dropUnique('program_binding_scope_unique');
            $table->dropColumn('evaluation_rule_version_id');
        });
        Schema::dropIfExists('evaluation_rule_versions');
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropUnique('applications_id_round_unique');
            $table->dropColumn('registration_mode');
        });
    }
};
