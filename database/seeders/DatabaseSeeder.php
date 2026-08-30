<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Account;
use App\Models\CreditCard;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Tumhare Bank Accounts Insert kar rahe hain
        Account::create([
            'bank_name' => 'Federal Jupiter',
            'account_role' => 'The Engine (Primary Hub)',
            'current_balance' => 2500.00,
            'mab_required' => 0.00
        ]);

        Account::create([
            'bank_name' => 'HDFC Bank',
            'account_role' => 'The Vault (Emergency)',
            'current_balance' => 10000.00,
            'mab_required' => 10000.00
        ]);

        Account::create([
            'bank_name' => 'Bandhan Bank',
            'account_role' => 'Safety Net',
            'current_balance' => 2800.00,
            'mab_required' => 2000.00
        ]);

        // 2. Tumhare Credit Cards Insert kar rahe hain
        CreditCard::create([
            'card_name' => 'Utkarsh SuperMoney UPI',
            'total_limit' => 32000.00,
            'available_limit' => 32000.00,
            'billing_date' => 15, // Dummy date, ise tum baad mein update kar sakte ho
            'is_upi_enabled' => true
        ]);

        CreditCard::create([
            'card_name' => 'Slice CC UPI',
            'total_limit' => 25000.00,
            'available_limit' => 25000.00,
            'billing_date' => 20, 
            'is_upi_enabled' => true
        ]);

        CreditCard::create([
            'card_name' => 'Bandhan Mastercard',
            'total_limit' => 36000.00,
            'available_limit' => 36000.00,
            'billing_date' => 10,
            'is_upi_enabled' => false // Mastercard QR scan nahi hota
        ]);
    }
}