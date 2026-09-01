<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Transaction; 
use App\Models\Account;      
use App\Models\CreditCard;
use App\Models\User; 
use Carbon\Carbon; 

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

        // 1. STATEMENT GENERATION DETECTION
        $isStatement = preg_match('/(statement is ready|statement for your.*total due)/i', $smsLower);

        // 🎯 2. UPGRADED AMOUNT PARSER (Specially for App Notifications)
        $amount = 0;
        
        // Priority A: Starts with INR/Rs/₹ (Standard SMS)
        if (preg_match('/(?:rs\.?|inr|₹)\s*([\d,]+\.\d{2}|[\d,]+)/i', $sms, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }
        // Priority B: App Notifications format ("Paid 200", "Sent 200", "Received 200")
        if ($amount == 0 && preg_match('/(?:debited|credited|deposited|spent|received|paid|sent|payment of)\s+(?:to|from|with|by|for)?\s*([\d,]+\.\d{2}|[\d,]+)/i', $sms, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }
        // Priority C: Strict floating number fallback
        if ($amount == 0 && preg_match('/([\d,]+\.\d{2})\s+(?:debited|credited|deposited|spent|received|paid|sent)/i', $sms, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }

        if ($amount <= 0 && !$isStatement) {
            Log::error("Amount parse failed for Notification/SMS: " . $sms);
            return response()->json(['error' => 'Could not parse valid amount'], 422);
        }

        // 🎯 3. CREDIT YA DEBIT TYPE
        $type = 'UNKNOWN'; 
        if (preg_match('/(received your payment|payment of.*credited|payment of.*received)/i', $smsLower)) {
            $type = 'INCOME';
        } elseif (preg_match('/\b(debited|spent|paid|withdrawn|sent|transfer)\b/i', $smsLower)) {
            $type = 'EXPENSE';
        } elseif (preg_match('/\b(credited|received|repayment|added|deposit|deposited|refund|cashback)\b/i', $smsLower)) {
            $type = 'INCOME';
        } elseif (preg_match('/\bcredit\b/i', $smsLower) && !preg_match('/\bcredit\s*card\b/i', $smsLower)) {
            $type = 'INCOME';
        } else {
            $type = 'EXPENSE'; 
        }

        // 🎯 4. OPTIMIZED SOURCE IDENTIFICATION (Includes App Support)
        $sourceName = 'Unknown';
        $sourceType = 'ACCOUNT';

        if (str_contains($smsLower, 'slice')) {
            if (preg_match('/(a\/c|account|savings)/i', $smsLower)) {
                $sourceName = 'Slice'; $sourceType = 'ACCOUNT'; 
            } else {
                $sourceName = 'Slice CC'; $sourceType = 'CREDIT_CARD';
            }
        } 
        elseif (preg_match('/(supermoney|utkarsh|supercard|utkspr)/i', $smsLower)) {
            $sourceName = 'Utkarsh SuperMoney'; $sourceType = 'CREDIT_CARD';
        } 
        elseif (preg_match('/(bdnsms|bandhan)/i', $smsLower)) { // bandhan will catch app notifications
            if (preg_match('/(credit|card|cc)\b/i', $smsLower) && !preg_match('/(a\/c|account|ac)/i', $smsLower)) {
                $sourceName = 'Bandhan CC'; $sourceType = 'CREDIT_CARD';
            } else {
                $sourceName = 'Bandhan'; $sourceType = 'ACCOUNT'; 
            }
        } 
        elseif (preg_match('/(jupiter|federal|fedbnk|fedmobile)/i', $smsLower)) { // Fedmobile added
            $sourceName = 'Federal Bank'; $sourceType = 'ACCOUNT'; 
        } elseif (str_contains($smsLower, 'hdfc')) {
            $sourceName = 'HDFC'; $sourceType = 'ACCOUNT';
        } 
        // Auto-Route general UPI apps to Daily UPI Hub
        elseif (preg_match('/(gpay|google pay|phonepe|paytm|cred)/i', $smsLower)) {
            $sourceName = 'Federal Bank'; $sourceType = 'ACCOUNT'; 
        }

        // 5. HANDLE AUTOMATIC STATEMENT SHIFT 
        if ($isStatement && $sourceType === 'CREDIT_CARD') {
            $card = CreditCard::where('user_id', $userId)->where('card_name', 'LIKE', '%' . $sourceName . '%')->first();
            if ($card) {
                $card->billed_outstanding += $card->unbilled_outstanding;
                $card->unbilled_outstanding = 0;
                $card->save();
                
                Transaction::create([
                    'user_id' => $userId, 
                    'amount' => $amount > 0 ? $amount : $card->billed_outstanding,
                    'type' => 'STATEMENT', 
                    'category' => 'Bill Generation',
                    'source' => $card->card_name,
                    'source_type' => 'CREDIT_CARD',
                    'raw_sms' => $sms, 
                    'description' => 'Auto-Generated via Bank SMS', 
                    'date' => Carbon::now('Asia/Kolkata'),
                ]);
                return response()->json(['status' => 'success', 'message' => 'Statement automatically generated']);
            }
        }

        // 6. CATEGORIZER
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
            $category = ($type === 'INCOME') ? 'Income / Cashback' : 'Other Expenses';
        }

        // 🛑 7. THE NEW APP NOTIFICATION & UTR SHIELD
        if ($sourceName !== 'Unknown' && $amount > 0) {
            $refId = null;
            // Extract exactly 12 digits (Standard for Indian UPI UTRs)
            if (preg_match('/(?<!\d)(\d{12})(?!\d)/', $sms, $refMatches)) {
                $refId = $refMatches[1];
            } elseif (preg_match('/(?:ref|utr|upi|txn|no\.?|id).*?([a-zA-Z0-9]{8,15})/i', $sms, $refMatches)) {
                $refId = $refMatches[1];
            }

            // A) Exact Duplicate Check
            $exactDuplicate = Transaction::where('user_id', $userId)
                ->where('raw_sms', $sms)
                ->where('created_at', '>=', now()->subSeconds(30))
                ->first();

            if ($exactDuplicate) {
                return response()->json(['status' => 'success', 'message' => 'Exact duplicate ignored']);
            }

            // 🎯 B) FUZZY NOTIFICATION SHIELD (New)
            // Agar SMS aur App Notification ek sath aate hain (same amount, same type, under 60 secs) toh doosre wale ko block kar dega
            $fuzzyDuplicate = Transaction::where('user_id', $userId)
                ->where('amount', $amount)
                ->where('type', $type)
                ->where('created_at', '>=', now()->subSeconds(60))
                ->first();

            if ($fuzzyDuplicate) {
                // Agar pehle notification aaya jisme UTR nahi tha, aur ab SMS aaya jisme UTR hai, toh usko update kar lo bina balance kaate
                if ($refId && !str_contains($fuzzyDuplicate->raw_sms, $refId)) {
                    $fuzzyDuplicate->raw_sms = $fuzzyDuplicate->raw_sms . ' | UTR: ' . $refId;
                    $fuzzyDuplicate->save();
                }
                return response()->json(['status' => 'success', 'message' => 'App Notification/SMS overlap blocked.']);
            }

            // C) UTR Shield Check
            if ($refId) {
                $utrDuplicate = Transaction::where('user_id', $userId)
                    ->where('raw_sms', 'LIKE', "%{$refId}%")
                    ->where('type', $type) 
                    ->where('created_at', '>=', now()->subDays(7))
                    ->exists();
                    
                if ($utrDuplicate) {
                    return response()->json(['status' => 'success', 'message' => 'UTR already processed for this specific type']);
                }
            }
        }

        // 8. DATABASE & BALANCE SYNC 
        try {
            DB::beginTransaction();
            $finalMatchedName = $sourceName;

            if ($sourceName !== 'Unknown') {
                if ($sourceType === 'ACCOUNT') {
                    $account = Account::where('user_id', $userId)->where('bank_name', 'LIKE', '%' . $sourceName . '%')->first();
                    
                    if ($account) {
                        $finalMatchedName = $account->bank_name; // Record exactly as in DB
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
                        $finalMatchedName = $card->card_name; // Record exactly as in DB
                        if ($type === 'EXPENSE' || $type === 'DEBIT') {
                            $card->decrement('available_limit', $amount);
                            $card->increment('unbilled_outstanding', $amount);
                        } else {
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
                    } else {
                        Log::warning("Card not found for name: " . $sourceName);
                    }
                }
            }

            $transaction = Transaction::create([
                'user_id'     => $userId,
                'amount'      => $amount,
                'type'        => $type,
                'category'    => $category,
                'source'      => $finalMatchedName,
                'source_type' => $sourceType,
                'raw_sms'     => $sms,
                'description' => 'Via Auto App/SMS',
                'date'        => Carbon::now('Asia/Kolkata'),
            ]);

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Transaction saved', 'data' => $transaction]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to save transaction: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}