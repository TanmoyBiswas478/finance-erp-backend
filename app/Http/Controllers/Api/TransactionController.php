<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\CreditCard;
use Illuminate\Support\Facades\Log; // 🕵️‍♂️ Spy Log

class TransactionController extends Controller
{
    public function store(Request $request)
    {
        // 🕵️‍♂️ Jasoos Code: Yeh exact data print karega jo phone ne bheja hai
        Log::info('===== NAYA SMS AAYA =====');
        Log::info('Phone ne yeh exact data bheja: ', $request->all());

        // 1. DATA MAPPING (Extra keys add ki hain just in case)
        $type = strtoupper(trim($request->type ?? $request->transaction_type ?? 'EXPENSE'));
        $sourceNameOriginal = trim($request->source ?? $request->source_id ?? $request->bank ?? $request->account ?? '');
        $amount = (float) $request->amount;
        $sourceType = strtoupper(trim($request->source_type ?? 'ACCOUNT'));

        Log::info("Extract hua -> Type: {$type}, Bank Name: '{$sourceNameOriginal}', Amount: {$amount}");

        // 2. Database save
        $transactionData = $request->all();
        $transactionData['type'] = $type;
        $transactionData['source'] = $sourceNameOriginal;
        $transaction = Transaction::create($transactionData);

        // 3. Aliases
        $bankAliases = [
            'federal' => 'Jupiter',
            'jupiter' => 'Jupiter',
            'hdfc'    => 'HDFC',
            'bandhan' => 'Bandhan',
            'slice'   => 'Slice'
        ];

        $cardAliases = [
            'utkarsh'    => 'Utkarsh SuperMoney',
            'supermoney' => 'Utkarsh SuperMoney',
            'slice cc'   => 'Slice CC',
            'bandhan cc' => 'Bandhan CC'
        ];

        // 4. Source find karo
        $source = null;
        if ($sourceType === 'ACCOUNT') {
            $resolvedName = $bankAliases[strtolower($sourceNameOriginal)] ?? $sourceNameOriginal;
            $source = Account::whereRaw('LOWER(bank_name) = ?', [strtolower($resolvedName)])->first();
        } elseif ($sourceType === 'CREDIT_CARD') {
            $resolvedName = $cardAliases[strtolower($sourceNameOriginal)] ?? $sourceNameOriginal;
            $source = CreditCard::whereRaw('LOWER(card_name) = ?', [strtolower($resolvedName)])->first();
        }

        // 5. Balance update
        if ($source) {
            Log::info("✅ Bank Mil Gaya! Puraana Balance: " . ($source->current_balance ?? $source->available_limit));
            if ($type === 'DEBIT' || $type === 'EXPENSE') { 
                if ($sourceType === 'ACCOUNT') {
                    $source->decrement('current_balance', $amount);
                } elseif ($sourceType === 'CREDIT_CARD') {
                    $source->decrement('available_limit', $amount);
                    $source->increment('unbilled_outstanding', $amount); 
                }
            } 
            Log::info("✅ Balance Deduct ho gaya!");
        } else {
            // ❌ Agar fail hua, toh yahan batayega kyun fail hua
            Log::error("❌ ERROR: Bank DB mein nahi mila! Phone ne yeh naam bheja hai: '" . $sourceNameOriginal . "'");
        }

        return response()->json(['status' => 'success', 'data' => $transaction]);
    }
}