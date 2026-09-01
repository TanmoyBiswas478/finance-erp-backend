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

        // 🤖 2. LLM-POWERED INTELLIGENT PARSER (Gemini 1.5 Flash)
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

            $prompt = "Analyze this bank notification/SMS: \"{$sms}\". " .
                "Extract the following details and return ONLY a valid JSON object without markdown formatting: " .
                "{\"amount\": float, \"type\": \"EXPENSE\" or \"INCOME\", \"bank_name\": \"Federal Bank\" or \"Bandhan\" or \"HDFC\" or \"Slice\" or \"Slice CC\" or \"Utkarsh SuperMoney\" or \"Bandhan CC\", \"category\": \"Food & Shopping\" or \"Shopping\" or \"Travel\" or \"Bills & Utilities\" or \"Income / Cashback\" or \"Other Expenses\"}";

            try {
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
                    $aiResponseText = $response->json('candidates.0.content.parts.0.text', '');

                    // 👇 Yeh line add karlo temporary debugging ke liye
                    Log::info("Gemini Raw AI Response: " . $aiResponseText);
                    // Clean markdown code blocks if AI returns them
                    $cleanedJson = trim(str_replace(['```json', '```'], '', $aiResponseText));
                    $parsedData = json_decode($cleanedJson, true);

                    // 👇 Yeh bhi check karlo ki decode hone ke baad amount kya mila
                    Log::info("Parsed Amount: " . ($parsedData['amount'] ?? 'NULL'));

                    if (isset($parsedData['amount'])) {
                        $amount = floatval($parsedData['amount']);
                        $type = isset($parsedData['type']) ? strtoupper($parsedData['type']) : 'EXPENSE';
                        $sourceName = isset($parsedData['bank_name']) ? $parsedData['bank_name'] : 'Unknown';
                        $category = isset($parsedData['category']) ? $parsedData['category'] : 'Other Expenses';

                        // Map source type
                        if (str_contains(strtolower($sourceName), 'cc') || str_contains(strtolower($sourceName), 'supermoney')) {
                            $sourceType = 'CREDIT_CARD';
                        } else {
                            $sourceType = 'ACCOUNT';
                        }
                    }
                } else {
                    Log::error("Gemini API Error: " . $response->body());
                }
            } catch (\Exception $e) {
                Log::error("AI Parsing Exception: " . $e->getMessage());
            }
        }

        // Fallback if AI fails or amount is 0
        if ($amount <= 0 && !$isStatement) {
            Log::error("AI Amount parse failed for Notification/SMS: " . $sms);
            return response()->json([
                'error' => 'Could not parse valid amount via AI',
                'received_text' => $sms
            ], 422);
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

        // 🛑 7. THE APP NOTIFICATION & UTR SHIELD
        if ($sourceName !== 'Unknown' && $amount > 0) {
            $refId = null;
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

            // B) FUZZY NOTIFICATION SHIELD
            $fuzzyDuplicate = Transaction::where('user_id', $userId)
                ->where('amount', $amount)
                ->where('type', $type)
                ->where('created_at', '>=', now()->subSeconds(60))
                ->first();

            if ($fuzzyDuplicate) {
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
                    $card = CreditCard::where('user_id', $userId)->where('card_name', 'LIKE', '%' . $sourceName . '%')->first();

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

            $transaction = Transaction::create([
                'user_id'     => $userId,
                'amount'      => $amount,
                'type'        => $type,
                'category'    => $category,
                'source'      => $finalMatchedName,
                'source_type' => $sourceType,
                'raw_sms'     => $sms,
                'description' => 'Via AI Auto Webhook',
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
