<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('transfer_target_type')->nullable()->after('source_id'); 
            $table->unsignedBigInteger('transfer_target_id')->nullable()->after('transfer_target_type');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['transfer_target_type', 'transfer_target_id']);
        });
    }
};