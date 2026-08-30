<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Saari tables mein user_id add kar rahe hain
        $tables = ['transactions', 'accounts', 'credit_cards', 'emis', 'category_budgets'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table_bp) {
                    // Nullable rakha hai taaki purana data crash na ho
                    $table_bp->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
                });
            }
        }
    }

    public function down()
    {
        $tables = ['transactions', 'accounts', 'credit_cards', 'emis', 'category_budgets'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table_bp) {
                    $table_bp->dropForeign(['user_id']);
                    $table_bp->dropColumn('user_id');
                });
            }
        }
    }
};