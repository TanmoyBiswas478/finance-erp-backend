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

        // 1. AMOUNT PARSE KARNA (Super Smart Regex - Bina 'Rs.' ke bhi kaam karega)
        $amount = 0;
        
        // Pattern 1: Amount keyword ke aage ya peeche number dhoondhna
        if (preg_match('/(?:rs\.?|inr|₹|amount|debited|credited|received|paid)\s*[:\-]?\s*(?:rs\.?|inr|₹)?\s*([\d,]+\.\d{2}|[\d,]+)/i', $sms, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }
        // Pattern 2: Agar shuruat mein number ho (e.g., "500 debited from...")
        if ($amount == 0 && preg_match('/^([\d,]+\.?\d*)\s*(?:debited|credited|spent|received)/i', $sms, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }
        // Pattern 3: Aakhiri umeed (Sirf koi standard 2-decimal point wala number dhoondho e.g. 100.50)
        if ($amount == 0 && preg_match('/([\d,]+\.\d{2})/', $sms, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }

        if ($amount <= 0) {
            return response()->json(['error' => 'Could not parse valid amount'], 422);
        }

        // 2. SOURCE PEHCHANNA
        $sourceName = 'Unknown';
        $sourceType = 'ACCOUNT';

        // Credit Cards & Bank Check
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

        // 3. CREDIT YA DEBIT TYPE (Fix: Har tarah ke Credit ko detect karega)
        $type = 'EXPENSE'; 
        if (preg_match('/(credit|received|repayment|added|deposit|refund)/i', $smsLower)) {
            $type = 'INCOME';
        }

        // 🧠 4. THE SMART BRAIN (Auto-Categorizer for BOTH Debit & Credit)
        $category = 'Unknown'; 
        
        // Comprehensive Dictionary
        $categoryMap = [
            'Food & Shopping' => ['swiggy', 'zomato', 'blinkit', 'zepto', 'instamart', 'mcdonalds', 'kfc', 'dominos', 'restaurant', 'food', 'grocery', 'bigbasket', 'dmart'],
            'Shopping' => ['amazon', 'flipkart', 'myntra', 'ajio', 'meesho', 'zudio', 'pantaloons', 'lifestyle', 'mall', 'store', 'retail', 'reliance'],
            'Travel' => ['uber', 'ola', 'rapido', 'namma yatri', 'irctc', 'makemytrip', 'goibibo', 'flight', 'ticket', 'petrol', 'fuel', 'indian oil', 'bharat petroleum', 'hpcl', 'metro', 'pump'],
            'Bills & Utilities' => ['recharge', 'jio', 'airtel', 'vi', 'bsnl', 'electricity', 'water', 'gas', 'broadband', 'wifi', 'emi', 'subscription', 'netflix', 'prime', 'spotify']
        ];

        // 1. Pehle pure message ko scan karo (chahe Credit ho ya Debit)
        foreach ($categoryMap as $catName => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($smsLower, $keyword)) {
                    $category = $catName;
                    break 2; // Match milte hi dhoondhna band karo
                }
            }
        }

        // 2. Agar koi keyword nahi mila (Unknown reh gaya), tab hum fallback lagayenge
        if ($category === 'Unknown') {
            if ($type === 'INCOME') {
                $category = 'Income / Cashback';
            } else {
                $category = 'Other Expenses';
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
                'description' => 'Via Auto SMS', // Note column ke liye
                'date'        => now(),
            ]);

            // Balance Update (Fix for Credit sync)
            if ($sourceName !== 'Unknown') {
                if ($sourceType === 'ACCOUNT') {
                    $account = Account::where('bank_name', $sourceName)->first();
                    if ($account) {
                        if ($type === 'EXPENSE' || $type === 'DEBIT') {
                            $account->decrement('current_balance', $amount);
                        } else {
                            $account->increment('current_balance', $amount); // INCOME yahan add hoga
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