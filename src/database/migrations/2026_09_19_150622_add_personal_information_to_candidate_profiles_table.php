<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table) {
            $table->string('ethnicity')->nullable();
            $table->string('religion')->nullable();
            $table->date('citizen_id_issued_date')->nullable();
            $table->string('citizen_id_issued_place')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'ethnicity',
                'religion',
                'citizen_id_issued_date',
                'citizen_id_issued_place',
            ]);
        });
    }
};
