<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_round_id')->constrained()->restrictOnDelete();
            $table->foreignId('major_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('admission_method_id')->index()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quota');
            $table->decimal('minimum_score', 8, 3)->nullable();
            $table->decimal('previous_cutoff_score', 8, 3)->nullable();
            $table->decimal('tuition_fee', 15, 2)->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->unique(['admission_round_id', 'major_id', 'admission_method_id'], 'admission_programs_offering_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_programs');
    }
};
