<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('candidate_code', 30)->unique();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('citizen_id', 20)->nullable()->unique();
            $table->string('phone', 20)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('province_code', 20)->nullable()->index();
            $table->string('high_school_code', 30)->nullable();
            $table->string('high_school_name', 255)->nullable();
            $table->unsignedSmallInteger('graduation_year')->nullable();
            $table->string('priority_area', 20)->nullable();
            $table->string('priority_object', 30)->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->string('profile_status', 30)->default('incomplete')->index();
            $table->timestamps();
            $table->string('ethnicity')->nullable();
            $table->string('religion')->nullable();
            $table->date('citizen_id_issued_date')->nullable();
            $table->string('citizen_id_issued_place')->nullable();
            $table->string('citizen_id_front_path', 500)->nullable();
            $table->string('citizen_id_back_path', 500)->nullable();
            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_profiles');
    }
};
