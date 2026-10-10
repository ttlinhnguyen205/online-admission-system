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
        Schema::table('admission_rounds', function (Blueprint $table) {
            $table->string('native_registration_state', 24)->default('legacy')->index();
            $table->foreignId('native_activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('native_activated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admission_rounds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('native_activated_by');
            $table->dropColumn(['native_registration_state', 'native_activated_at']);
        });
    }
};
