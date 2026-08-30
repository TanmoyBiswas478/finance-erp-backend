<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\CreditCard;

class TransactionController extends Controller
{
    public function store(Request $request)
    {
        // 1. Database mein transaction save karo
        $transaction = Transaction::create($request->all());

        // Naye columns ke hisaab se request data nikalo
        $type = $request->type; 
        $amount = $request->amount;
        $sourceType = $request->source_type;
        $sourceName = strtolower(trim($request->source)); // Incoming naam ko lowercase kar lo

        // 🎯 NAYA LOGIC: Aliases (Jiske multiple naam ho sakte hain)
        $bankAliases = [
            'federal' => 'Jupiter',
            'jupiter' => 'Jupiter',
            'hdfc'    => 'HDFC',
            'bandhan' => 'Bandhan',
            'slice'   => 'Slice'
        ];

        $cardAliases = [
            'utkarsh'    => 'Utkarsh SuperMoney',
            'supermoney' => 'Utkarsh SuperMoney',
            'slice'      => 'Slice CC',
            'bandhan'    => 'Bandhan CC'
        ];

        // Source find karo (Alias check karke aur Case-Insensitive dhoondh kar)
        $source = null;
        if ($sourceType === 'ACCOUNT') {
            // Agar alias array mein naam hai, toh wo lo, warna original naam
            $resolvedName = $bankAliases[$sourceName] ?? $request->source;
            $source = Account::whereRaw('LOWER(bank_name) = ?', [strtolower($resolvedName)])->first();
            
        } elseif ($sourceType === 'CREDIT_CARD') {
            $resolvedName = $cardAliases[$sourceName] ?? $request->source;
            $source = CreditCard::whereRaw('LOWER(card_name) = ?', [strtolower($resolvedName)])->first();
        }

        // Agar account/card database mein mil gaya, tabhi balance update karo
        if ($source) {
            // 2. SMART CALCULATIONS
            if ($type === 'DEBIT' || $type === 'EXPENSE') { 
                if ($sourceType === 'ACCOUNT') {
                    $source->decrement('current_balance', $amount);
                } elseif ($sourceType === 'CREDIT_CARD') {
                    $source->decrement('available_limit', $amount);
                    $source->increment('unbilled_outstanding', $amount); 
                }
            } 
            elseif ($type === 'CREDIT' || $type === 'INCOME') {
                if ($sourceType === 'ACCOUNT') {
                    $source->increment('current_balance', $amount);
                }
            }
            elseif ($type === 'TRANSFER' || $type === 'CC_BILL') {
                $source->decrement('current_balance', $amount);

                $targetType = $request->transfer_target_type;
                $targetId = $request->transfer_target_id; 

                if ($targetType === 'ACCOUNT') {
                    $target = Account::find($targetId);
                    if ($target) {
                        $target->increment('current_balance', $amount);
                    }
                } elseif ($targetType === 'CREDIT_CARD') {
                    $target = CreditCard::find($targetId);
                    if ($target) {
                        $target->increment('available_limit', $amount);
                        if ($target->billed_outstanding >= $amount) {
                            $target->decrement('billed_outstanding', $amount);
                        } else {
                            $target->billed_outstanding = 0;
                            $target->save();
                        }
                    }
                }
            }
        }

        return response()->json(['status' => 'success', 'data' => $transaction]);
    }
}