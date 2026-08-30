<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\WebhookController;

Route::post('/transactions', [TransactionController::class, 'store']);
Route::get('/dashboard', [DashboardController::class, 'index']);
// Nayi route bill generate karne ke liye
Route::post('/credit-cards/{id}/generate-statement', [DashboardController::class, 'generateStatement']);
// EMI pay karne ki route
Route::post('/emis/{id}/pay', [DashboardController::class, 'payEmi']);
Route::post('/login', [AuthController::class, 'login']);
// Isme middleware nahi lagayenge varna phone se request block ho jayegi
Route::post('/webhook/transaction', [AutomationController::class, 'handleSmsWebhook']);



Route::post('/webhook/transaction', [WebhookController::class, 'handleSms']);