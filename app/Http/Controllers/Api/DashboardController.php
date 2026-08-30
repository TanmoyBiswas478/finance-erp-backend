<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Transaction;
use Carbon\Carbon;
use App\Models\Emi;

class DashboardController extends Controller
{
    public function index()
    {
        $accounts = Account::all();
        $creditCards = CreditCard::all();
        $currentMonth = Carbon::now()->month;
        $currentYear = Carbon::now()->year;
        
        // Expenses
        $currentMonthExpense = Transaction::whereIn('type', ['DEBIT', 'EXPENSE'])
            ->whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear)
            ->sum('amount');
            
        // 🎯 NAYA: Income Total for Dashboard (Fix for Problem 2)
        $currentMonthIncome = Transaction::whereIn('type', ['CREDIT', 'INCOME'])
            ->whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear)
            ->sum('amount');
            
        // 🎯 NAYA: Recent Transactions Mapped Format (Fix for Problem 4 - Note & Type)
        $recent_transactions = Transaction::orderBy('created_at', 'desc')->limit(5)->get()->map(function($t) {
            return [
                'id'       => $t->id,
                'date'     => $t->date ?? $t->transaction_date,
                'category' => $t->category ?? 'Uncategorized',
                'note'     => $t->description ?? $t->raw_sms ?? '--', // Note me description dikhega
                'type'     => $t->type ?? $t->transaction_type, // Type ab blank nahi rahega
                'amount'   => $t->amount
            ];
        });

        // Category Expenses
        $category_expenses = Transaction::whereIn('type', ['DEBIT', 'EXPENSE'])
            ->whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear)
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->get();

        $active_emis = Emi::where('is_active', true)->get();
        $budgets = \App\Models\CategoryBudget::all();
        $budget_alerts = [];

        foreach ($budgets as $budget) {
            $spent = 0;
            foreach ($category_expenses as $expense) {
                if (strtolower($expense->category) === strtolower($budget->category_name)) {
                    $spent = $expense->total;
                    break;
                }
            }
            $percentage = $budget->budget_limit > 0 ? ($spent / $budget->budget_limit) * 100 : 0;
            $budget_alerts[] = [
                'category'   => $budget->category_name,
                'limit'      => $budget->budget_limit,
                'spent'      => $spent,
                'percentage' => min($percentage, 100),
                'is_danger'  => $percentage >= 90,
                'is_warning' => $percentage >= 75 && $percentage < 90
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'accounts'              => $accounts,
                'credit_cards'          => $creditCards,
                'current_month_expense' => $currentMonthExpense,
                'current_month_income'  => $currentMonthIncome, // Ab frontend Credit bhi dikhayega!
                'recent_transactions'   => $recent_transactions,
                'category_expenses'     => $category_expenses,
                'active_emis'           => $active_emis,
                'budget_alerts'         => $budget_alerts 
            ]
        ], 200);
    }
    
    // (Baaki purane generateStatement aur payEmi functions wese hi rakhna)
    public function generateStatement($id)
    {
        $card = CreditCard::findOrFail($id);
        $card->billed_outstanding += $card->unbilled_outstanding;
        $card->unbilled_outstanding = 0;
        $card->save();
        return response()->json(['status' => 'success', 'message' => 'Statement Generated Successfully!']);
    }

    public function payEmi($id)
    {
        $emi = \App\Models\Emi::findOrFail($id);
        if($emi->paid_installments >= $emi->total_installments) {
            return response()->json(['status' => 'error', 'message' => 'EMI already fully paid']);
        }

        $sourceName = 'Unknown';
        if ($emi->source_type === 'ACCOUNT') {
            $source = \App\Models\Account::find($emi->source_id);
            if ($source) {
                $source->decrement('current_balance', $emi->emi_amount);
                $sourceName = $source->bank_name;
            }
        } elseif ($emi->source_type === 'CREDIT_CARD') {
            $source = \App\Models\CreditCard::find($emi->source_id);
            if ($source) {
                $source->decrement('available_limit', $emi->emi_amount);
                $source->increment('unbilled_outstanding', $emi->emi_amount);
                $sourceName = $source->card_name;
            }
        }

        \App\Models\Transaction::create([
            'date'        => \Carbon\Carbon::now()->format('Y-m-d'),
            'amount'      => $emi->emi_amount,
            'type'        => 'EXPENSE',
            'category'    => 'EMI Payment',
            'source_type' => $emi->source_type,
            'source'      => $sourceName,
            'description' => $emi->emi_name . ' - Installment ' . ($emi->paid_installments + 1),
        ]);

        $emi->increment('paid_installments');
        if($emi->paid_installments >= $emi->total_installments) {
            $emi->is_active = false;
        }
        $emi->save();

        return response()->json(['status' => 'success', 'message' => 'EMI Paid Successfully!']);
    }
}