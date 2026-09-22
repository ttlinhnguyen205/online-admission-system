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
        if (! Schema::hasColumn('candidate_profiles', 'ethnicity')) {
            Schema::table('candidate_profiles', function (Blueprint $table) {
                $table->string('ethnicity')->nullable()->after('gender');
            });
        }

        if (! Schema::hasColumn('candidate_profiles', 'religion')) {
            Schema::table('candidate_profiles', function (Blueprint $table) {
                $table->string('religion')->nullable()->after('ethnicity');
            });
        }

        if (! Schema::hasColumn('candidate_profiles', 'citizen_id_issued_date')) {
            Schema::table('candidate_profiles', function (Blueprint $table) {
                $table->date('citizen_id_issued_date')->nullable()->after('citizen_id');
            });
        }

        if (! Schema::hasColumn('candidate_profiles', 'citizen_id_issued_place')) {
            Schema::table('candidate_profiles', function (Blueprint $table) {
                $table->string('citizen_id_issued_place')->nullable()->after('citizen_id_issued_date');
            });
        }

        if (! Schema::hasColumn('candidate_profiles', 'citizen_id_front_path')) {
            Schema::table('candidate_profiles', function (Blueprint $table) {
                $table->string('citizen_id_front_path', 500)->nullable()->after('photo_path');
            });
        }

        if (! Schema::hasColumn('candidate_profiles', 'citizen_id_back_path')) {
            Schema::table('candidate_profiles', function (Blueprint $table) {
                $table->string('citizen_id_back_path', 500)->nullable()->after('citizen_id_front_path');
            });
        }

        if (! Schema::hasColumn('candidate_profiles', 'verified_by')) {
            Schema::table('candidate_profiles', function (Blueprint $table) {
                $table->foreignId('verified_by')->nullable()->after('profile_status')->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('candidate_profiles', 'verified_at')) {
            Schema::table('candidate_profiles', function (Blueprint $table) {
                $table->timestamp('verified_at')->nullable()->after('verified_by');
            });
        }

        if (! Schema::hasColumn('candidate_profiles', 'verification_note')) {
            Schema::table('candidate_profiles', function (Blueprint $table) {
                $table->text('verification_note')->nullable()->after('verified_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('candidate_profiles', 'verified_by')) {
            Schema::table('candidate_profiles', function (Blueprint $table) {
                $table->dropConstrainedForeignId('verified_by');
            });
        }

        foreach ([
            'ethnicity',
            'religion',
            'citizen_id_issued_date',
            'citizen_id_issued_place',
            'citizen_id_front_path',
            'citizen_id_back_path',
            'verified_at',
            'verification_note',
        ] as $column) {
            if (Schema::hasColumn('candidate_profiles', $column)) {
                Schema::table('candidate_profiles', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
