<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handleSms(Request $request)
    {
        $sms = $request->input('raw_sms');

        if (!$sms) {
            return response()->json(['error' => 'No SMS received'], 400);
        }

        $smsLower = strtolower($sms);

        // 1. AMOUNT NIKALNE KA LOGIC
        $amount = 0;
        if (preg_match('/(?:rs\.?|inr)\s*([\d,]+\.?\d*)/i', $sms, $matches)) {
            $amount = floatval(str_replace(',', '', $matches[1]));
        }

        // 2. SOURCE & TYPE PEHCHAN-NE KA LOGIC
        $sourceName = 'Unknown';
        $sourceType = 'ACCOUNT'; // Default account

        // --- Credit Cards ---
        if (str_contains($smsLower, 'slice')) {
            $sourceName = 'Slice CC';
            $sourceType = 'CREDIT_CARD';
        } elseif (str_contains($smsLower, 'supermoney') || str_contains($smsLower, 'utkarsh')) {
            $sourceName = 'Utkarsh SuperMoney';
            $sourceType = 'CREDIT_CARD';
        } elseif (str_contains($smsLower, 'bandhan') && str_contains($smsLower, 'card')) {
            $sourceName = 'Bandhan CC';
            $sourceType = 'CREDIT_CARD';
        } 
        // --- Bank Accounts ---
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

        // 3. CREDIT YA DEBIT CHECK
        $type = 'DEBIT'; 
        if (str_contains($smsLower, 'credited') || str_contains($smsLower, 'received') || str_contains($smsLower, 'repayment')) {
            $type = 'CREDIT';
        }

        // 4. LOG MEIN PRINT KARNA (Check karne ke liye)
        Log::info('New Transaction Detected:', [
            'Source' => $sourceName, 
            'Type' => $sourceType, 
            'Amount' => $amount, 
            'Transaction_Type' => $type
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'source_name' => $sourceName,
                'source_type' => $sourceType,
                'amount' => $amount,
                'type' => $type
            ]
        ]);
    }
}