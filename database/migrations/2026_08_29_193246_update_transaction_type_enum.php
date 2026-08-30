<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB; // Yeh import zaroori hai

return new class extends Migration
{
    public function up(): void
    {
        // Database ko naye words sikhane ka raw SQL command
        DB::statement("ALTER TABLE transactions MODIFY transaction_type ENUM('DEBIT', 'CREDIT', 'TRANSFER', 'CC_BILL') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE transactions MODIFY transaction_type ENUM('DEBIT', 'CREDIT') NOT NULL");
    }
};