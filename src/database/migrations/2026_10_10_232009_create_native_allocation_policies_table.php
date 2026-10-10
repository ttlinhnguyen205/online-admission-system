<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('native_allocation_policies')) {
            $columns = Schema::getColumnListing('native_allocation_policies');
            $expected = ['id', 'admission_round_id', 'version', 'status', 'payload', 'content_hash', 'created_by', 'approved_by', 'approved_at', 'approval_slot', 'created_at', 'updated_at'];
            sort($columns);
            sort($expected);
            $indexes = collect(Schema::getIndexes('native_allocation_policies'));
            if ($columns !== $expected || DB::table('native_allocation_policies')->exists()
                || $indexes->count() !== 4
                || ! $indexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['admission_round_id', 'version'])
                || count(Schema::getForeignKeys('native_allocation_policies')) !== 3) {
                throw new RuntimeException('Unexpected existing allocation policy table; refusing migration recovery.');
            }
            Schema::table('native_allocation_policies', function (Blueprint $table): void {
                $table->unique(['admission_round_id', 'approval_slot'], 'native_policy_one_approval_unique');
            });

            return;
        }
        Schema::create('native_allocation_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admission_round_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status')->default('draft');
            $table->json('payload');
            $table->char('content_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedTinyInteger('approval_slot')->nullable();
            $table->timestamps();
            $table->unique(['admission_round_id', 'version']);
            $table->unique(['admission_round_id', 'approval_slot'], 'native_policy_one_approval_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('native_allocation_policies');
    }
};
