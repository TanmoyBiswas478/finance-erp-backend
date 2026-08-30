<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->decimal('billed_outstanding', 10, 2)->default(0)->after('available_limit');
            $table->decimal('unbilled_outstanding', 10, 2)->default(0)->after('billed_outstanding');
            $table->integer('due_date_offset')->default(20)->after('billing_date'); 
        });
    }

    public function down(): void
    {
        Schema::table('credit_cards', function (Blueprint $table) {
            $table->dropColumn(['billed_outstanding', 'unbilled_outstanding', 'due_date_offset']);
        });
    }
};