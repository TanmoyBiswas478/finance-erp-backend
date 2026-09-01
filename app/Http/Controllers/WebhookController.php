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
use Illuminate\Support\Facades\Http;

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

        // 🤖 2. LLM-POWERED INTELLIGENT PARSER WITH RETRY & APP NOTIFICATION SUPPORT
        $amount = 0;
        $type = 'EXPENSE';
        $sourceName = 'Unknown';
        $sourceType = 'ACCOUNT';
        $category = 'Other Expenses';

        if (!$isStatement) {
            $apiKey = env('GEMINI_API_KEY');
            if (!$apiKey) {
                Log::error("GEMINI_API_KEY is missing in .env file");
                return response()->json(['error' => 'AI API Key not configured'], 500);
            }

            // Enhanced prompt to catch Federal Bank / Jupiter app notifications even without explicit SMS keywords
            $prompt = "Analyze this bank notification or push notification: \"{$sms}\". " .
                "Extract the following details and return ONLY a valid JSON object without markdown formatting: " .
                "{\"amount\": float, \"type\": \"EXPENSE\" or \"INCOME\", \"bank_name\": \"Federal Bank\" or \"Bandhan\" or \"HDFC\" or \"Slice\" or \"Slice CC\" or \"Utkarsh SuperMoney\" or \"Bandhan CC\", \"category\": \"Food & Shopping\" or \"Shopping\" or \"Travel\" or \"Bills & Utilities\" or \"Income / Cashback\" or \"Other Expenses\"}. " .
                "Note: If the notification mentions FedMobile, Jupiter, or a UPI app credit/debit linked to an account, map bank_name to 'Federal Bank'.";

            $maxRetries = 3;
            $attempt = 0;
            $apiSuccessful = false;

            while ($attempt < $maxRetries && !$apiSuccessful) {
                try {
                    $attempt++;
                    $response = Http::timeout(10)->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}", [
                        'contents' => [
                            [
                                'parts' => [
                                    ['text' => $prompt]
                                ]
                            ]
                        ]
                    ]);

                    if ($response->successful()) {
                        $apiSuccessful = true;
                        $aiResponseText = $response->json('candidates.0.content.parts.0.text', '');

                        Log::info("Gemini Raw AI Response: " . $aiResponseText);
                        $cleanedJson = trim(str_replace(['```json', '```'], '', $aiResponseText));
                        $parsedData = json_decode($cleanedJson, true);

                        if (isset($parsedData['amount'])) {
                            $amount = floatval($parsedData['amount']);
                            $type = isset($parsedData['type']) ? strtoupper($parsedData['type']) : 'EXPENSE';
                            $sourceName = isset($parsedData['bank_name']) ? $parsedData['bank_name'] : 'Unknown';
                            $category = isset($parsedData['category']) ? $parsedData['category'] : 'Other Expenses';

                            if (str_contains(strtolower($sourceName), 'cc') || str_contains(strtolower($sourceName), 'supermoney')) {
                                $sourceType = 'CREDIT_CARD';
                            } else {
                                $sourceType = 'ACCOUNT';
                            }
                        }
                    } else {
                        if ($response->status() === 503 && $attempt < $maxRetries) {
                            usleep(1000000); // Wait 1 second on high demand
                            continue;
                        }
                        Log::error("Gemini API Error: " . $response->body());
                        break;
                    }
                } catch (\Exception $e) {
                    if ($attempt < $maxRetries) {
                        usleep(1000000);
                        continue;
                    }
                    Log::error("AI Parsing Exception: " . $e->getMessage());
                }
            }
        }

        if ($amount <= 0 && !$isStatement) {
            Log::error("AI Amount parse failed for Notification/SMS: " . $sms);
            return response()->json([
                'error' => 'Could not parse valid amount via AI',
                'received_text' => $sms
            ], 422);
        }

        // 5. HANDLE AUTOMATIC STATEMENT SHIFT 
        if ($isStatement && $sourceType === 'CREDIT_CARD') {
            $card = CreditCard::where('user_id', $userId)
                ->where(function($query) use ($sourceName) {
                    $query->where('card_name', 'LIKE', '%' . $sourceName . '%')
                          ->orWhere('card_name', 'LIKE', '%Slice%')
                          ->orWhere('card_name', 'LIKE', '%Bandhan%')
                          ->orWhere('card_name', 'LIKE', '%SuperMoney%');
                })->first();

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
                    'description' => 'Statement Generated for ' . $card->card_name,
                    'date' => Carbon::now('Asia/Kolkata'),
                ]);
                return response()->json(['status' => 'success', 'message' => 'Statement automatically generated']);
            }
        }

        // 8. DATABASE & BALANCE SYNC WITH FLEXIBLE MAPPING & SHIELDS
        try {
            DB::beginTransaction();
            $finalMatchedName = $sourceName;

            if ($sourceName !== 'Unknown') {
                if ($sourceType === 'ACCOUNT') {
                    $account = Account::where('user_id', $userId)
                        ->where(function($query) use ($sourceName) {
                            $query->where('bank_name', 'LIKE', '%' . $sourceName . '%')
                                  ->orWhere('bank_name', 'LIKE', '%Federal%')
                                  ->orWhere('bank_name', 'LIKE', '%Jupiter%')
                                  ->orWhere('bank_name', 'LIKE', '%Bandhan%');
                        })->first();

                    if ($account) {
                        $finalMatchedName = $account->bank_name;
                        if ($type === 'EXPENSE' || $type === 'DEBIT') {
                            $account->decrement('current_balance', $amount);
                        } else {
                            $account->increment('current_balance', $amount);
                        }
                    } else {
                        Log::warning("Account not found for name: " . $sourceName);
                    }
                } elseif ($sourceType === 'CREDIT_CARD') {
                    $card = CreditCard::where('user_id', $userId)
                        ->where(function($query) use ($sourceName) {
                            $query->where('card_name', 'LIKE', '%' . $sourceName . '%')
                                  ->orWhere('card_name', 'LIKE', '%Slice%')
                                  ->orWhere('card_name', 'LIKE', '%Bandhan%')
                                  ->orWhere('card_name', 'LIKE', '%SuperMoney%');
                        })->first();

                    if ($card) {
                        $finalMatchedName = $card->card_name;
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

            // 🛑 7. THE APP NOTIFICATION & UTR SHIELD (Source-aware to prevent multi-bank parallel blocks)
            if ($finalMatchedName !== 'Unknown' && $amount > 0) {
                $refId = null;
                if (preg_match('/(?<!\d)(\d{12})(?!\d)/', $sms, $refMatches)) {
                    $refId = $refMatches[1];
                } elseif (preg_match('/(?:ref|utr|upi|txn|no\.?|id).*?([a-zA-Z0-9]{8,15})/i', $sms, $refMatches)) {
                    $refId = $refMatches[1];
                }

                // Exact Duplicate Check
                $exactDuplicate = Transaction::where('user_id', $userId)
                    ->where('raw_sms', $sms)
                    ->where('created_at', '>=', now()->subSeconds(30))
                    ->first();

                if ($exactDuplicate) {
                    DB::rollBack();
                    return response()->json(['status' => 'success', 'message' => 'Exact duplicate ignored']);
                }

                // Fuzzy Shield (Scoped per bank source so simultaneous multi-bank credits work flawlessly)
                $fuzzyDuplicate = Transaction::where('user_id', $userId)
                    ->where('amount', $amount)
                    ->where('type', $type)
                    ->where('source', $finalMatchedName)
                    ->where('created_at', '>=', now()->subSeconds(30))
                    ->first();

                if ($fuzzyDuplicate) {
                    DB::rollBack();
                    return response()->json(['status' => 'success', 'message' => 'Same bank transaction overlap blocked.']);
                }

                // UTR Shield Check
                if ($refId) {
                    $utrDuplicate = Transaction::where('user_id', $userId)
                        ->where('raw_sms', 'LIKE', "%{$refId}%")
                        ->where('type', $type)
                        ->where('created_at', '>=', now()->subDays(7))
                        ->exists();

                    if ($utrDuplicate) {
                        DB::rollBack();
                        return response()->json(['status' => 'success', 'message' => 'UTR already processed for this specific type']);
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
                'description' => 'Credited/Debited via ' . $finalMatchedName, // Dynamic bank name note
                'date'        => Carbon::now('Asia/Kolkata'),
            ]);

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'AI Transaction saved', 'data' => $transaction]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to save AI transaction: ' . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}