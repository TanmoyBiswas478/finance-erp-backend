<?php

namespace App\Http\Controllers; // Check kar lena tumhara namespace yahi hai ya 'Api' hai

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Transaction;
use App\Models\Account;      // NAYA IMPORT: Balance katne ke liye
use App\Models\CreditCard;   // NAYA IMPORT: Limit katne ke liye

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
            $sourceName = 'Bandhan';
            $sourceType = 'ACCOUNT';
        }

        // 3. CREDIT YA DEBIT TYPE
        $type = 'EXPENSE'; // Default debit / kharcha
        if (str_contains($smsLower, 'credited') || str_contains($smsLower, 'received') || str_contains($smsLower, 'repayment')) {
            $type = 'INCOME';
        }

        // 4. DATABASE MEIN TRANSACTION SAVE KARNA AUR BALANCE UPDATE KARNA
        try {
            DB::beginTransaction();

            // A. Transaction Save Karo (title hata diya kyunki humne DB se wo column shayad hata diya tha)
            $transaction = Transaction::create([
                'amount'      => $amount,
                'type'        => $type,
                'category'    => 'Uncategorized',
                'source'      => $sourceName,
                'source_type' => $sourceType,
                'raw_sms'     => $sms,
                'date'        => now(),
            ]);

            // B. YAHAN HAI ASLI JADOO: Balance Update Logic jo missing tha!
            if ($sourceName !== 'Unknown') {
                if ($sourceType === 'ACCOUNT') {
                    $account = Account::where('bank_name', $sourceName)->first();
                    if ($account) {
                        if ($type === 'EXPENSE') {
                            $account->decrement('current_balance', $amount);
                        } else {
                            $account->increment('current_balance', $amount);
                        }
                    }
                } elseif ($sourceType === 'CREDIT_CARD') {
                    $card = CreditCard::where('card_name', $sourceName)->first();
                    if ($card) {
                        if ($type === 'EXPENSE') {
                            $card->decrement('available_limit', $amount);
                            $card->increment('unbilled_outstanding', $amount);
                        } else {
                            $card->increment('available_limit', $amount);
                        }
                    }
                }
            }

            DB::commit();

            Log::info('✅ SUCCESS: Transaction Saved AND Balance Updated!', ['id' => $transaction->id, 'amount' => $amount, 'bank' => $sourceName]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Transaction saved and balance updated',
                'data'    => $transaction
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ Failed to save transaction: ' . $e->getMessage());

            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to save: ' . $e->getMessage()
            ], 500);
        }
    }
}