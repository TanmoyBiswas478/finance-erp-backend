<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\WebhookController;

// 🟢 PUBLIC ROUTES (Bina login ke chalenge)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Isme middleware nahi lagayenge varna MacroDroid/Shortcuts se request block ho jayegi
Route::post('/webhook/transaction', [WebhookController::class, 'handleSms']);


// 🔴 PROTECTED ROUTES (Sirf Login hone ke baad token ke sath chalenge)
Route::middleware('auth:sanctum')->group(function () {
    
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Dashboard & EMI
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::post('/credit-cards/{id}/generate-statement', [DashboardController::class, 'generateStatement']);
    Route::post('/emis/{id}/pay', [DashboardController::class, 'payEmi']);
    
    // Accounts Manual CRUD
    Route::put('/accounts/{id}', [DashboardController::class, 'updateAccount']);
    Route::delete('/accounts/{id}', [DashboardController::class, 'deleteAccount']);

    // Credit Cards Manual CRUD
    Route::put('/credit-cards/{id}', [DashboardController::class, 'updateCreditCard']);
    Route::delete('/credit-cards/{id}', [DashboardController::class, 'deleteCreditCard']);

    // Transactions CRUD
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::put('/transactions/{id}', [TransactionController::class, 'update']);
    Route::delete('/transactions/{id}', [TransactionController::class, 'destroy']);
});