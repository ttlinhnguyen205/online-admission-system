<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_wishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_program_id')->index()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('priority');
            $table->decimal('calculated_score', 8, 3)->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->unique(['application_id', 'priority']);
            $table->unique(['application_id', 'admission_program_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_wishes');
    }
};
