<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Account;
use App\Models\CreditCard;
use App\Models\CategoryBudget;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Tumhara user dhoondho (pehla user ya tumhari email wala)
        $user = User::first();

        if (!$user) {
            // Agar koi user nahi hai, toh pehle ek dummy user bana lo
            $user = User::create([
                'name' => 'Tanmoy',
                'email' => 'tanmoybiswas478@gmail.com', // Apni wali email daal dena
                'password' => bcrypt('password123')
            ]);
        }

        // 1. Bank Accounts (user_id ke sath)
        Account::create(['user_id' => $user->id, 'bank_name' => 'Jupiter', 'account_role' => 'SAVINGS', 'current_balance' => 2368.00]);
        Account::create(['user_id' => $user->id, 'bank_name' => 'HDFC', 'account_role' => 'SAVINGS', 'current_balance' => 10000.00]);
        Account::create(['user_id' => $user->id, 'bank_name' => 'Bandhan', 'account_role' => 'SAVINGS', 'current_balance' => 4400.00]);
        Account::create(['user_id' => $user->id, 'bank_name' => 'Slice', 'account_role' => 'SAVINGS', 'current_balance' => 0.00]);

        // 2. Credit Cards (user_id ke sath)
        CreditCard::create([
            'user_id' => $user->id,
            'card_name' => 'Utkarsh SuperMoney',
            'total_limit' => 32000.00,
            'available_limit' => 29845.40,
            'billed_outstanding' => 0.00,
            'unbilled_outstanding' => 2155.00,
            'billing_date' => 1
        ]);
        CreditCard::create([
            'user_id' => $user->id,
            'card_name' => 'Slice CC',
            'total_limit' => 26000.00,
            'available_limit' => 26000.00,
            'billed_outstanding' => 0.00,
            'unbilled_outstanding' => 0.00,
            'billing_date' => 21
        ]);
        CreditCard::create([
            'user_id' => $user->id,
            'card_name' => 'Bandhan CC',
            'total_limit' => 36000.00,
            'available_limit' => 36000.00,
            'billed_outstanding' => 0.00,
            'unbilled_outstanding' => 0.00,
            'billing_date' => 20
        ]);

        // 3. Budgets (user_id ke sath)
        CategoryBudget::create(['user_id' => $user->id, 'category_name' => 'Food & Shopping', 'budget_limit' => 4000]);
        CategoryBudget::create(['user_id' => $user->id, 'category_name' => 'Travel', 'budget_limit' => 2000]);
        CategoryBudget::create(['user_id' => $user->id, 'category_name' => 'Shopping', 'budget_limit' => 1000]);
        CategoryBudget::create(['user_id' => $user->id, 'category_name' => 'Bills & Utilities', 'budget_limit' => 1000]);
    }
}