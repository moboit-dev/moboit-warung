<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/users', [AuthController::class, 'createUser']);
    Route::get('/users', [UserController::class, 'index']);
    Route::put('/users/{user}', [UserController::class, 'update']);

    // Endpoint khusus, didaftarkan SEBELUM apiResource supaya "evaluate-cart"
    // tidak ketangkap oleh route {promotion} milik show().
    Route::post('/promotions/evaluate-cart', [PromotionController::class, 'evaluateCart']);
    Route::apiResource('promotions', PromotionController::class);

    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('products', ProductController::class);

    // Stok: read-only + adjust manual (bukan apiResource, karena sengaja
    // tidak ada update() langsung — lihat catatan desain di StockController).
    Route::get('/stocks', [StockController::class, 'index']);
    Route::get('/stocks/{product}', [StockController::class, 'show']);
    Route::post('/stocks/{product}/adjust', [StockController::class, 'adjust']);

    Route::get('/stock-movements', [StockMovementController::class, 'index']);
    Route::post('/stock-movements', [StockMovementController::class, 'store']);

    Route::get('/reports/sales', [ReportController::class, 'sales']);
    Route::get('/reports/sales/transactions', [ReportController::class, 'salesTransactions']);
    Route::get('/reports/sales/items', [ReportController::class, 'salesByItem']);
    Route::get('/reports/sales/categories', [ReportController::class, 'salesByCategory']);

    Route::put('/tenant/settings', [TenantController::class, 'updateSettings']);

    Route::post('/transactions', [TransactionController::class, 'store']);
});
