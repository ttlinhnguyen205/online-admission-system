<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_wish_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('final_score', 8, 3);
            $table->unsignedInteger('rank')->nullable();
            $table->string('decision', 30)->default('waiting')->index();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_results');
    }
};
