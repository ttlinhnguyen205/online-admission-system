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
        Schema::table('admission_wishes', function (Blueprint $table) {
            $table->unsignedBigInteger('candidate_major_offering_id')->nullable();
            $table->unique(['application_id', 'candidate_major_offering_id'], 'wish_application_offering_unique');
            $table->index(['candidate_major_offering_id', 'admission_program_id'], 'wish_offering_program_index');
            $table->foreign(['candidate_major_offering_id', 'admission_program_id'], 'wish_offering_program_foreign')
                ->references(['id', 'admission_program_id'])->on('candidate_major_offerings')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admission_wishes', function (Blueprint $table) {
            $table->dropForeign('wish_offering_program_foreign');
            $table->dropIndex('wish_offering_program_index');
            $table->dropUnique('wish_application_offering_unique');
            $table->dropColumn('candidate_major_offering_id');
        });
    }
};
