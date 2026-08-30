<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class AutomationController extends Controller
{
    // Yeh API endpoint phone se data receive karega
    public function handleSmsWebhook(Request $request)
    {
        // 1. Phone se aaya hua data validate karo
        $request->validate([
            'source_name' => 'required|string', // e.g., 'HDFC', 'Jupiter', 'SuperMoney'
            'source_type' => 'required|string', // 'ACCOUNT' ya 'CREDIT_CARD'
            'amount' => 'required|numeric',
            'type' => 'required|string', // 'DEBIT' ya 'CREDIT'
            'description' => 'nullable|string'
        ]);

        $amount = $request->amount;
        $type = strtoupper($request->type); // DEBIT/CREDIT
        $sourceType = strtoupper($request->source_type);

        // Security ke liye log save karo (taaki debug kar sakein)
        Log::info("Webhook Received: ", $request->all());

        try {
            // ==========================================
            // BANK ACCOUNT AUTOMATION LOGIC
            // ==========================================
            if ($sourceType === 'ACCOUNT') {
                $account = Account::where('bank_name', 'LIKE', '%' . $request->source_name . '%')->first();
                
                if (!$account) return response()->json(['error' => 'Account not found'], 404);

                if ($type === 'DEBIT') {
                    $account->current_balance -= $amount;
                } else {
                    $account->current_balance += $amount;
                }
                $account->save();

                // Transaction Entry
                Transaction::create([
                    'category' => 'Automated Expense', // Default category
                    'amount' => $amount,
                    'transaction_date' => now()->toDateString(),
                    'transaction_type' => $type,
                    'source_type' => 'ACCOUNT',
                    'source_id' => $account->id,
                    'description' => $request->description ?? 'Auto-synced via SMS'
                ]);
            } 
            
            // ==========================================
            // CREDIT CARD AUTOMATION LOGIC
            // ==========================================
            else if ($sourceType === 'CREDIT_CARD') {
                $card = CreditCard::where('card_name', 'LIKE', '%' . $request->source_name . '%')->first();
                
                if (!$card) return response()->json(['error' => 'Card not found'], 404);

                if ($type === 'DEBIT') { // Matlab card pe kharcha hua hai
                    $card->available_limit -= $amount;
                    $card->unbilled_outstanding += $amount;
                } else { // Refund aaya hai
                    $card->available_limit += $amount;
                    $card->unbilled_outstanding -= $amount;
                }
                $card->save();

                // Transaction Entry
                Transaction::create([
                    'category' => 'Automated Expense',
                    'amount' => $amount,
                    'transaction_date' => now()->toDateString(),
                    'transaction_type' => $type,
                    'source_type' => 'CREDIT_CARD',
                    'source_id' => $card->id,
                    'description' => $request->description ?? 'Auto-synced via SMS'
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Automation successful, database updated!'
            ], 200);

        } catch (\Exception $e) {
            Log::error("Webhook Error: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}