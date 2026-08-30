<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\WebhookController;

// 🟢 PUBLIC ROUTES (Bina login (token) ke chalenge)
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Isme middleware nahi lagayenge varna MacroDroid/Zapier se request block ho jayegi
Route::post('/webhook/transaction', [WebhookController::class, 'handleSms']);


// 🔴 PROTECTED ROUTES (Sirf Login hone ke baad token ke sath chalenge)
Route::middleware('auth:sanctum')->group(function () {
    
    // Logout
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Dashboard & Actions
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::post('/credit-cards/{id}/generate-statement', [DashboardController::class, 'generateStatement']);
    Route::post('/emis/{id}/pay', [DashboardController::class, 'payEmi']);
    
    // Manual Transactions Add karne ki route (Iska form hum aage UI mein banayenge)
    Route::post('/transactions', [TransactionController::class, 'store']);
    
});