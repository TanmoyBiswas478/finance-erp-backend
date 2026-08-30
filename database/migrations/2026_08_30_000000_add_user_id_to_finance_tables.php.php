<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        $tables = ['transactions', 'accounts', 'credit_cards', 'emis', 'category_budgets'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table_bp) use ($table) {
                    // Check karo ki column pehle se toh nahi hai
                    if (!Schema::hasColumn($table, 'user_id')) {
                        $table_bp->unsignedBigInteger('user_id')->nullable();
                    }
                });
            }
        }
    }

    public function down()
    {
        $tables = ['transactions', 'accounts', 'credit_cards', 'emis', 'category_budgets'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table_bp) use ($table) {
                    if (Schema::hasColumn($table, 'user_id')) {
                        $table_bp->dropColumn('user_id');
                    }
                });
            }
        }
    }
};