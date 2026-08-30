<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Transaction; // Tumhara transaction model
use App\Models\Account;     // Tumhara account/card model

class WebhookController extends Controller
{
    public function handleSms(Request $request)
    {
        $sms = $request->input('raw_sms');

        if (!$sms) {
            return response()->json(['error' => 'No SMS received'], 400);
        }

        $smsLower = strtolower($sms);

        // 1. AMOUNT PARSE KARNA
        $amount = 0;
        if (preg_match('/(?:rs\.?|inr)\s*([\d,]+\.?\d*)/i', $sms, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }

        if ($amount <= 0) {
            return response()->json(['error' => 'Could not parse valid amount'], 422);
        }

        // 2. SOURCE & TYPE PEHCHANNA
        $sourceName = 'Unknown';
        $sourceType = 'ACCOUNT';

        // Credit Cards
        if (str_contains($smsLower, 'slice')) {
            $sourceName = 'Slice CC';
            $sourceType = 'CREDIT_CARD';
        } elseif (str_contains($smsLower, 'supermoney') || str_contains($smsLower, 'utkarsh') || str_contains($smsLower, 'supercard')) {
            $sourceName = 'Utkarsh SuperMoney';
            $sourceType = 'CREDIT_CARD';
        } elseif (str_contains($smsLower, 'bandhan') && (str_contains($smsLower, 'credit') || (str_contains($smsLower, 'card') && !str_contains($smsLower, 'debit')))) {
            // FIXED: Agar 'credit' likha hai, YA 'card' likha hai par 'debit' nahi likha hai
            $sourceName = 'Bandhan CC';
            $sourceType = 'CREDIT_CARD';
        } 
        // Bank Accounts
        elseif (str_contains($smsLower, 'jupiter') || str_contains($smsLower, 'federal')) {
            $sourceName = 'Jupiter';
            $sourceType = 'ACCOUNT';
        } elseif (str_contains($smsLower, 'hdfc')) {
            $sourceName = 'HDFC';
            $sourceType = 'ACCOUNT';
        } elseif (str_contains($smsLower, 'bandhan')) {
            // Agar Debit Card hua, toh wo upar filter na hokar yahan aayega
            $sourceName = 'Bandhan';
            $sourceType = 'ACCOUNT';
        }

        // 3. CREDIT YA DEBIT TYPE
        $type = 'EXPENSE'; // Default debit / kharcha
        if (str_contains($smsLower, 'credited') || str_contains($smsLower, 'received') || str_contains($smsLower, 'repayment')) {
            $type = 'INCOME';
        }

        // 4. DATABASE MEIN TRANSACTION SAVE KARNA
        try {
            DB::beginTransaction();

            $transaction = Transaction::create([
                'title'       => 'Auto SMS: ' . $sourceName,
                'amount'      => $amount,
                'type'        => $type,
                'category'    => 'Uncategorized',
                'source'      => $sourceName,
                'source_type' => $sourceType,
                'raw_sms'     => $sms,
                'date'        => now(),
            ]);

            DB::commit();

            Log::info('Transaction Saved Successfully:', ['id' => $transaction->id, 'amount' => $amount]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Transaction saved to database',
                'data'    => $transaction
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to save transaction: ' . $e->getMessage());

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to save: ' . $e->getMessage()
            ], 500);
        }
    }
}