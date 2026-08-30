<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\CreditCard;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    public function store(Request $request)
    {
        $rawSms = $request->raw_sms ?? '';

        // Default values
        $amount = 0;
        $sourceName = 'Unknown';
        $type = 'EXPENSE';
        $sourceType = 'ACCOUNT';

        // 1. 🧠 SMS PARSING LOGIC (Asli Magic Yahan Hai)
        if (!empty($rawSms)) {
            // Amount nikalne ka logic (Check karega Rs. ya INR ke baad ka number)
            if (preg_match('/(?:Rs\.?|INR)\s*([\d,]+\.?\d*)/i', $rawSms, $matches)) {
                $amount = (float) str_replace(',', '', $matches[1]);
            }

            // Bank ka naam detect karne ka logic
            $bankAliases = [
                'federal' => 'Jupiter',
                'jupiter' => 'Jupiter',
                'hdfc'    => 'HDFC',
                'bandhan' => 'Bandhan',
                'slice'   => 'Slice'
            ];

            foreach ($bankAliases as $keyword => $realName) {
                // Agar SMS ke andar keyword mil gaya, toh bank ka naam set kar do
                if (stripos($rawSms, $keyword) !== false) {
                    $sourceName = $realName;
                    break;
                }
            }
        }

        // 2. Database mein transaction save karo
        $transaction = Transaction::create([
            'raw_sms' => $rawSms,
            'amount' => $amount,
            'type' => $type,
            'category' => 'Uncategorized',
            'source' => $sourceName,
            'source_type' => $sourceType,
            'date' => now()
        ]);

        // 3. Balance Update Logic
        if ($sourceName !== 'Unknown' && $amount > 0) {
            $source = Account::where('bank_name', $sourceName)->first();
            if ($source) {
                $source->decrement('current_balance', $amount);
                Log::info("✅ SUCCESS: {$sourceName} se ₹{$amount} deduct ho gaye!");
            } else {
                Log::error("❌ ERROR: {$sourceName} DB mein nahi mila.");
            }
        }

        return response()->json(['status' => 'success', 'data' => $transaction]);
    }
}