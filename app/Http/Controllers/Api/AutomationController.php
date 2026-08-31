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
    // Yeh API endpoint phone se data receive karega directly
    public function handleSmsWebhook(Request $request)
    {
        $request->validate([
            'source_name' => 'required|string', 
            'source_type' => 'required|string', 
            'amount' => 'required|numeric',
            'type' => 'required|string',
            'description' => 'nullable|string'
        ]);

        $amount = $request->amount;
        $type = in_array(strtoupper($request->type), ['DEBIT', 'EXPENSE']) ? 'EXPENSE' : 'INCOME';
        $sourceType = strtoupper($request->source_type);

        Log::info("Webhook Received: ", $request->all());

        try {
            if ($sourceType === 'ACCOUNT') {
                $account = Account::where('bank_name', 'LIKE', '%' . $request->source_name . '%')->first();
                
                if (!$account) return response()->json(['error' => 'Account not found'], 404);

                if ($type === 'EXPENSE') {
                    $account->decrement('current_balance', $amount);
                } else {
                    $account->increment('current_balance', $amount);
                }

                Transaction::create([
                    'category' => 'Automated Expense', 
                    'amount' => $amount,
                    'transaction_date' => now()->toDateString(),
                    'transaction_type' => $type,
                    'source_type' => 'ACCOUNT',
                    'source_id' => $account->id,
                    'description' => $request->description ?? 'Auto-synced via explicit API'
                ]);
            } 
            
            else if ($sourceType === 'CREDIT_CARD') {
                $card = CreditCard::where('card_name', 'LIKE', '%' . $request->source_name . '%')->first();
                
                if (!$card) return response()->json(['error' => 'Card not found'], 404);

                if ($type === 'EXPENSE') { 
                    $card->decrement('available_limit', $amount);
                    $card->increment('unbilled_outstanding', $amount);
                } else { 
                    // Waterfall Logic
                    $card->increment('available_limit', $amount);
                    $remaining = $amount;
                    if ($card->billed_outstanding > 0) {
                        $deduct = min($remaining, $card->billed_outstanding);
                        $card->decrement('billed_outstanding', $deduct);
                        $remaining -= $deduct;
                    }
                    if ($remaining > 0) {
                        $card->decrement('unbilled_outstanding', min($remaining, $card->unbilled_outstanding));
                    }
                }

                Transaction::create([
                    'category' => 'Automated Expense',
                    'amount' => $amount,
                    'transaction_date' => now()->toDateString(),
                    'transaction_type' => $type,
                    'source_type' => 'CREDIT_CARD',
                    'source_id' => $card->id,
                    'description' => $request->description ?? 'Auto-synced via explicit API'
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