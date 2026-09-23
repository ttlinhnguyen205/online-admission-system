<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE candidate_certificates MODIFY certificate_type ENUM('ielts', 'toeic', 'sat') NOT NULL, MODIFY score DECIMAL(8, 2) NOT NULL");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::table('candidate_certificates')->where('certificate_type', 'toeic')->update(['certificate_type' => 'ielts']);
        DB::statement("ALTER TABLE candidate_certificates MODIFY certificate_type ENUM('ielts', 'sat') NOT NULL, MODIFY score DECIMAL(8, 3) NOT NULL");
    }
};
