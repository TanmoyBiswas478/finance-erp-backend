<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Transaction; 
use App\Models\Account;      
use App\Models\CreditCard;   

class WebhookController extends Controller
{
    public function handleSms(Request $request)
    {
        $sms = $request->input('raw_sms');

        if (!$sms) {
            return response()->json(['error' => 'No SMS received'], 400);
        }

        $smsLower = strtolower($sms);

        // 1. AMOUNT PARSE KARNA (Super Smart Regex)
        $amount = 0;
        
        if (preg_match('/(?:rs\.?|inr|₹|amount|debited|credited|received|paid)\s*[:\-]?\s*(?:rs\.?|inr|₹)?\s*([\d,]+\.\d{2}|[\d,]+)/i', $sms, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }
        if ($amount == 0 && preg_match('/^([\d,]+\.?\d*)\s*(?:debited|credited|spent|received)/i', $sms, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }
        if ($amount == 0 && preg_match('/([\d,]+\.\d{2})/', $sms, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }

        if ($amount <= 0) {
            return response()->json(['error' => 'Could not parse valid amount'], 422);
        }

        // 2. SOURCE PEHCHANNA
        $sourceName = 'Unknown';
        $sourceType = 'ACCOUNT';

        if (str_contains($smsLower, 'slice')) {
            $sourceName = 'Slice CC'; $sourceType = 'CREDIT_CARD';
        } elseif (preg_match('/(supermoney|utkarsh|supercard)/i', $smsLower)) {
            $sourceName = 'Utkarsh SuperMoney'; $sourceType = 'CREDIT_CARD';
        } elseif (str_contains($smsLower, 'bandhan') && preg_match('/(credit|card)/i', $smsLower) && !str_contains($smsLower, 'debit')) {
            $sourceName = 'Bandhan CC'; $sourceType = 'CREDIT_CARD';
        } elseif (preg_match('/(jupiter|federal)/i', $smsLower)) {
            $sourceName = 'Jupiter'; $sourceType = 'ACCOUNT';
        } elseif (str_contains($smsLower, 'hdfc')) {
            $sourceName = 'HDFC'; $sourceType = 'ACCOUNT';
        } elseif (str_contains($smsLower, 'bandhan')) {
            $sourceName = 'Bandhan'; $sourceType = 'ACCOUNT';
        }

        // 3. CREDIT YA DEBIT TYPE 
        $type = 'EXPENSE'; 
        if (preg_match('/(credit|received|repayment|added|deposit|refund)/i', $smsLower)) {
            $type = 'INCOME';
        }

        // 🧠 4. THE SMART BRAIN (Auto-Categorizer jo delete ho gaya tha)
        $category = 'Unknown'; 
        $categoryMap = [
            'Food & Shopping' => ['swiggy', 'zomato', 'blinkit', 'zepto', 'instamart', 'mcdonalds', 'kfc', 'dominos', 'restaurant', 'food', 'grocery', 'bigbasket', 'dmart'],
            'Shopping' => ['amazon', 'flipkart', 'myntra', 'ajio', 'meesho', 'zudio', 'pantaloons', 'lifestyle', 'mall', 'store', 'retail', 'reliance'],
            'Travel' => ['uber', 'ola', 'rapido', 'namma yatri', 'irctc', 'makemytrip', 'goibibo', 'flight', 'ticket', 'petrol', 'fuel', 'indian oil', 'bharat petroleum', 'hpcl', 'metro', 'pump'],
            'Bills & Utilities' => ['recharge', 'jio', 'airtel', 'vi', 'bsnl', 'electricity', 'water', 'gas', 'broadband', 'wifi', 'emi', 'subscription', 'netflix', 'prime', 'spotify']
        ];

        foreach ($categoryMap as $catName => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($smsLower, $keyword)) {
                    $category = $catName;
                    break 2; 
                }
            }
        }

        if ($category === 'Unknown') {
            if ($type === 'INCOME') {
                $category = 'Income / Cashback';
            } else {
                $category = 'Other Expenses';
            }
        }

        // 🛑 4.5 THE MERGE & UPGRADE SHIELD (Fixed $rawSms to $sms)
        if ($sourceName !== 'Unknown' && $amount > 0) {
            
            $refId = null;
            if (preg_match('/(?:ref|utr|upi|txn|no\.?|id).*?([a-zA-Z0-9]{8,15})/i', $sms, $refMatches)) {
                $refId = $refMatches[1];
            }

            $exactDuplicate = Transaction::where('raw_sms', $sms)
                ->where('created_at', '>=', now()->subSeconds(30))
                ->first();

            if ($exactDuplicate) {
                return response()->json(['status' => 'success', 'message' => 'Exact duplicate ignored']);
            }

            if ($refId) {
                $utrDuplicate = Transaction::where('raw_sms', 'LIKE', "%{$refId}%")
                    ->where('created_at', '>=', now()->subDays(7))
                    ->exists();
                    
                if ($utrDuplicate) {
                    Log::info("🛑 UTR DUPLICATE BLOCKED: ₹{$amount} for {$sourceName}.");
                    return response()->json(['status' => 'success', 'message' => 'UTR already processed']);
                }
            }

            if ($refId) {
                $recentTransactions = Transaction::where('amount', $amount)
                    ->where('source', $sourceName)
                    ->where('type', $type)
                    ->where('created_at', '>=', now()->subMinutes(3))
                    ->orderBy('created_at', 'desc')
                    ->get();
                    
                foreach ($recentTransactions as $oldTxn) {
                    if (!preg_match('/(?:ref|utr|upi|txn|no\.?|id).*?([a-zA-Z0-9]{8,15})/i', $oldTxn->raw_sms)) {
                        
                        $oldTxn->raw_sms = $sms . ' [Merged Notif]';
                        $oldTxn->save();
                        
                        Log::info("✅ UPGRADED: App Notification merged with SMS/Email UTR for ₹{$amount}");
                        return response()->json(['status' => 'success', 'message' => 'Merged App Notif with SMS']);
                    }
                }
            }
        }

        // 5. DATABASE & BALANCE SYNC
        try {
            DB::beginTransaction();

            $transaction = Transaction::create([
                'amount'      => $amount,
                'type'        => $type,
                'category'    => $category,
                'source'      => $sourceName,
                'source_type' => $sourceType,
                'raw_sms'     => $sms,
                'description' => 'Via Auto SMS',
                'date'        => now(),
            ]);

            if ($sourceName !== 'Unknown') {
                if ($sourceType === 'ACCOUNT') {
                    $account = Account::where('bank_name', $sourceName)->first();
                    if ($account) {
                        if ($type === 'EXPENSE' || $type === 'DEBIT') {
                            $account->decrement('current_balance', $amount);
                        } else {
                            $account->increment('current_balance', $amount); 
                        }
                    }
                } elseif ($sourceType === 'CREDIT_CARD') {
                    $card = CreditCard::where('card_name', $sourceName)->first();
                    if ($card) {
                        if ($type === 'EXPENSE' || $type === 'DEBIT') {
                            $card->decrement('available_limit', $amount);
                            $card->increment('unbilled_outstanding', $amount);
                        } else {
                            $card->increment('available_limit', $amount);
                            $card->decrement('unbilled_outstanding', min($amount, $card->unbilled_outstanding));
                        }
                    }
                }
            }

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Transaction saved', 'data' => $transaction]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to save transaction: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}