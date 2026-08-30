<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB; 

return new class extends Migration
{
    public function up(): void
    {
        // Column ka naam 'type' kiya aur Macrodroid ke 'EXPENSE' ko bhi allow kar diya
        DB::statement("ALTER TABLE transactions MODIFY type ENUM('DEBIT', 'CREDIT', 'TRANSFER', 'CC_BILL', 'EXPENSE', 'INCOME') NOT NULL");
    }

    public function down(): void
    {
        // Yahan bhi 'type' kar diya
        DB::statement("ALTER TABLE transactions MODIFY type VARCHAR(255) NOT NULL");
    }
};