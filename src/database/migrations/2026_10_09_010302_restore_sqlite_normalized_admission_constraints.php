<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** SQLite foreign-key toggles must run outside the schema transaction. */
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::getConnection()->transaction(function (): void {
                Schema::table('candidate_exam_results', function (Blueprint $table) {
                    $table->enum('exam_type', ['thpt', 'dgnl', 'dgtd', 'vsat', 'spt'])->change();
                    $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending')->change();
                });
                Schema::table('candidate_transcript_scores', function (Blueprint $table) {
                    $table->enum('grade_level', ['10', '11', '12'])->change();
                });
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /** Preserve original domain constraints when rolling back this schema repair. */
    }
};
