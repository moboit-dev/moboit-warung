<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/users', [AuthController::class, 'createUser']);

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
});
