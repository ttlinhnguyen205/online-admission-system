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
        Schema::create('native_method_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wish_method_binding_id')->constrained()->restrictOnDelete();
            $table->foreignId('evaluation_rule_version_id')->constrained()->restrictOnDelete();
            $table->string('algorithm_version', 40);
            $table->char('input_fingerprint', 64);
            $table->string('status', 24);
            $table->decimal('score', 6, 3)->nullable();
            $table->json('provenance');
            $table->json('reasons');
            $table->foreignId('evaluated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('evaluated_at');
            $table->timestamps();
            $table->unique(['wish_method_binding_id', 'algorithm_version'], 'native_evaluation_binding_algorithm_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('native_method_evaluations');
    }
};
