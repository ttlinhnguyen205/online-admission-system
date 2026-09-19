<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('high_schools', function (Blueprint $table) {
            $table->id();

            $table->foreignId('province_id')
                ->constrained('provinces')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('code', 30);

            $table->string('name');

            $table->timestamps();

            $table->unique(
                ['province_id', 'code'],
                'high_schools_province_code_unique'
            );

            $table->index(
                ['province_id', 'name'],
                'high_schools_province_name_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('high_schools');
    }
};