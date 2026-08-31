<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Transaction; 
use App\Models\Account;      
use App\Models\CreditCard;
use App\Models\User; 

class WebhookController extends Controller
{
    public function handleSms(Request $request)
    {
        $sms = $request->input('raw_sms');
        $userEmail = $request->input('user_email'); 

        if (!$sms) return response()->json(['error' => 'No SMS received'], 400);
        if (!$userEmail) return response()->json(['error' => 'User Email missing in webhook'], 400);

        $user = User::where('email', $userEmail)->first();
        if (!$user) return response()->json(['error' => 'User not found in ERP'], 404);

        $userId = $user->id; 
        $smsLower = strtolower($sms);

        // 🧠 1. STATEMENT GENERATION DETECTION (Smart Auto-Billed Shift)
        $isStatement = preg_match('/(statement is ready|statement for your.*total due)/i', $smsLower);

        // 2. AMOUNT PARSE (Added 'payment of', 'pay' for CC bill SMS formats)
        $amount = 0;
        if (preg_match('/(?:rs\.?|inr|₹|amount|debited|credited|received|paid|spent|sent|payment of|pay)\s*[:\-]?\s*(?:rs\.?|inr|₹)?\s*([\d,]+\.\d{2}|[\d,]+)/i', $sms, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }
        if ($amount == 0 && preg_match('/^([\d,]+\.?\d*)\s*(?:debited|credited|spent|received|paid|sent)/i', $sms, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }
        if ($amount == 0 && preg_match('/([\d,]+\.\d{2})/', $sms, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }

        // Agar amount na mile par SMS statement ka hai, toh fail mat karo.
        if ($amount <= 0 && !$isStatement) {
            return response()->json(['error' => 'Could not parse valid amount'], 422);
        }

        // 🎯 3. OPTIMIZED SOURCE PEHCHANNA (Added Sender IDs: UTKSPR, BDNSMS)
        $sourceName = 'Unknown';
        $sourceType = 'ACCOUNT';

        if (str_contains($smsLower, 'slice')) {
            $sourceName = 'Slice CC'; $sourceType = 'CREDIT_CARD';
        } 
        // Utkarsh SuperMoney detection optimized (with UTKSPR)
        elseif (preg_match('/(supermoney|utkarsh|supercard|utkspr)/i', $smsLower)) {
            $sourceName = 'Utkarsh SuperMoney'; $sourceType = 'CREDIT_CARD';
        } 
        // Bandhan CC detection optimized (with BDNSMS)
        elseif (preg_match('/(bdnsms)/i', $smsLower) || (str_contains($smsLower, 'bandhan') && preg_match('/\b(credit|card|cc)\b/i', $smsLower))) {
            $sourceName = 'Bandhan CC'; $sourceType = 'CREDIT_CARD';
        } 
        elseif (preg_match('/(jupiter|federal)/i', $smsLower)) {
            $sourceName = 'Jupiter'; $sourceType = 'ACCOUNT';
        } elseif (str_contains($smsLower, 'hdfc')) {
            $sourceName = 'HDFC'; $sourceType = 'ACCOUNT';
        } elseif (str_contains($smsLower, 'bandhan')) {
            $sourceName = 'Bandhan'; $sourceType = 'ACCOUNT';
        } 
        // UPI Apps detection
        elseif (preg_match('/(gpay|google pay)/i', $smsLower)) {
            $sourceName = 'GPay'; $sourceType = 'ACCOUNT';
        } elseif (str_contains($smsLower, 'phonepe')) {
            $sourceName = 'PhonePe'; $sourceType = 'ACCOUNT';
        } elseif (str_contains($smsLower, 'paytm')) {
            $sourceName = 'Paytm'; $sourceType = 'ACCOUNT';
        } elseif (str_contains($smsLower, 'cred')) {
            $sourceName = 'CRED'; $sourceType = 'ACCOUNT';
        }

        // 🛑 3.5 HANDLE AUTOMATIC STATEMENT SHIFT 
        if ($isStatement && $sourceType === 'CREDIT_CARD') {
            $card = CreditCard::where('user_id', $userId)->where('card_name', 'LIKE', '%' . $sourceName . '%')->first();
            if ($card) {
                // Pura unbilled shift karke billed mein daal do
                $card->billed_outstanding += $card->unbilled_outstanding;
                $card->unbilled_outstanding = 0;
                $card->save();
                
                // Dashboard mein dikhane ke liye ek record banega
                Transaction::create([
                    'user_id' => $userId, 
                    'amount' => $amount > 0 ? $amount : $card->billed_outstanding, // Bill exact amount ya total billed outstanding
                    'type' => 'STATEMENT', 
                    'category' => 'Bill Generation',
                    'source' => $sourceName, 
                    'source_type' => 'CREDIT_CARD',
                    'raw_sms' => $sms, 
                    'description' => 'Auto-Generated via Bank SMS', 
                    'date' => now(),
                ]);
                return response()->json(['status' => 'success', 'message' => 'Statement automatically generated']);
            }
        }

        // 4. CREDIT YA DEBIT TYPE (Payment Received = INCOME)
        $type = 'UNKNOWN'; 
        
        if (preg_match('/(received your payment|payment of.*credited|payment of.*received)/i', $smsLower)) {
            $type = 'INCOME';
        } elseif (preg_match('/\b(debited|spent|paid|withdrawn|sent)\b/i', $smsLower)) {
            $type = 'EXPENSE';
        } elseif (preg_match('/\b(credited|received|repayment|added|deposit|refund|cashback)\b/i', $smsLower)) {
            $type = 'INCOME';
        } elseif (preg_match('/\bcredit\b/i', $smsLower) && !preg_match('/\bcredit\s*card\b/i', $smsLower)) {
            $type = 'INCOME';
        } else {
            $type = 'EXPENSE'; 
        }

        // 🧠 5. THE SMART BRAIN (Auto-Categorizer)
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

        // 🛑 5.5 THE MERGE & UPGRADE SHIELD (Anti-Spam)
        if ($sourceName !== 'Unknown' && $amount > 0) {
            $refId = null;
            if (preg_match('/(?:ref|utr|upi|txn|no\.?|id).*?([a-zA-Z0-9]{8,15})/i', $sms, $refMatches)) {
                $refId = $refMatches[1];
            }

            // Anti-Spam: 30 seconds block
            $exactDuplicate = Transaction::where('user_id', $userId)
                ->where('raw_sms', $sms)
                ->where('created_at', '>=', now()->subSeconds(30))
                ->first();

            if ($exactDuplicate) {
                return response()->json(['status' => 'success', 'message' => 'Exact duplicate ignored']);
            }

            // UTR Shield: 7 Days
            if ($refId) {
                $utrDuplicate = Transaction::where('user_id', $userId)
                    ->where('raw_sms', 'LIKE', "%{$refId}%")
                    ->where('created_at', '>=', now()->subDays(7))
                    ->exists();
                    
                if ($utrDuplicate) {
                    return response()->json(['status' => 'success', 'message' => 'UTR already processed']);
                }
            }
        }

        // 6. DATABASE & BALANCE SYNC 
        try {
            DB::beginTransaction();

            $transaction = Transaction::create([
                'user_id'     => $userId,
                'amount'      => $amount,
                'type'        => $type,
                'category'    => $category,
                'source'      => $sourceName,
                'source_type' => $sourceType,
                'raw_sms'     => $sms,
                'description' => 'Via Auto App/SMS',
                'date'        => now(),
            ]);

            if ($sourceName !== 'Unknown') {
                if ($sourceType === 'ACCOUNT') {
                    $account = Account::where('user_id', $userId)->where('bank_name', 'LIKE', '%' . $sourceName . '%')->first();
                    
                    if ($account) {
                        if ($type === 'EXPENSE' || $type === 'DEBIT') {
                            $account->decrement('current_balance', $amount);
                        } else {
                            $account->increment('current_balance', $amount); 
                        }
                    } else {
                        Log::warning("Account not found for name: " . $sourceName);
                    }
                } elseif ($sourceType === 'CREDIT_CARD') {
                    $card = CreditCard::where('user_id', $userId)->where('card_name', 'LIKE', '%' . $sourceName . '%')->first();
                    
                    if ($card) {
                        if ($type === 'EXPENSE' || $type === 'DEBIT') {
                            $card->decrement('available_limit', $amount);
                            $card->increment('unbilled_outstanding', $amount);
                        } else {
                            // 🌊 THE WATERFALL PAYMENT LOGIC 
                            $card->increment('available_limit', $amount); // Limit wapas badha do
                            $remaining = $amount;
                            
                            // Step A: Billed outstanding pay karo
                            if ($card->billed_outstanding > 0) {
                                $deduct = min($remaining, $card->billed_outstanding);
                                $card->decrement('billed_outstanding', $deduct);
                                $remaining -= $deduct;
                            }
                            
                            // Step B: Agar extra diya hai, toh unbilled mein advance adjust karo
                            if ($remaining > 0) {
                                $card->decrement('unbilled_outstanding', min($remaining, $card->unbilled_outstanding));
                            }
                        }
                    } else {
                        Log::warning("Card not found for name: " . $sourceName);
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