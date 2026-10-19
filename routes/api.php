<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/shop-applications',
        [\App\Http\Controllers\Api\ShopApplicationController::class, 'store']
    )->middleware('throttle:10,1');

    Route::get('/shop-applications/mine',
        [\App\Http\Controllers\Api\ShopApplicationController::class, 'mine']
    );

    Route::get('/my-shops',
        [\App\Http\Controllers\Api\MyShopController::class, 'index']
    );

    Route::get('/admin/shop-applications',
        [\App\Http\Controllers\Api\Admin\ShopApprovalController::class, 'index']
    );

    Route::post('/admin/shop-applications/{applicationId}/review',
        [\App\Http\Controllers\Api\Admin\ShopApprovalController::class, 'review']
    )->whereNumber('applicationId');
});

Route::middleware('auth:sanctum')
    ->prefix('shops/{shopId}')
    ->whereNumber('shopId')
    ->group(function () {
        Route::get('/dashboard',
            [\App\Http\Controllers\Api\DashboardController::class, 'show']
        );

        Route::get('/cashiers',
            [\App\Http\Controllers\Api\ShopCashierController::class, 'index']
        );

        Route::post('/cashiers',
            [\App\Http\Controllers\Api\ShopCashierController::class, 'store']
        )->middleware('throttle:10,1');

        Route::delete('/cashiers/{userId}',
            [\App\Http\Controllers\Api\ShopCashierController::class, 'destroy']
        )->whereNumber('userId');
    });

Route::middleware('auth:sanctum')
    ->prefix('shops/{shopId}')
    ->whereNumber('shopId')
    ->group(function () {
        Route::get('/categories',
            [\App\Http\Controllers\Api\ProductCategoryController::class, 'index']
        );

        Route::post('/categories',
            [\App\Http\Controllers\Api\ProductCategoryController::class, 'store']
        )->middleware('throttle:20,1');
    });

Route::middleware('auth:sanctum')
    ->prefix('shops/{shopId}')
    ->whereNumber('shopId')
    ->group(function () {
        Route::get('/products',
            [\App\Http\Controllers\Api\ProductController::class, 'index']
        );

        Route::post('/products',
            [\App\Http\Controllers\Api\ProductController::class, 'store']
        )->middleware('throttle:20,1');

        Route::patch('/products/{productId}',
            [\App\Http\Controllers\Api\ProductController::class, 'update']
        )->whereNumber('productId');

        Route::delete('/products/{productId}/image',
            [\App\Http\Controllers\Api\ProductController::class, 'removeImage']
        )->whereNumber('productId');
    });

Route::middleware('auth:sanctum')
    ->prefix('shops/{shopId}')
    ->whereNumber('shopId')
    ->group(function () {
        Route::get('/inventory',
            [\App\Http\Controllers\Api\InventoryController::class, 'index']
        );

        Route::post('/products/{productId}/stock-adjustments',
            [\App\Http\Controllers\Api\InventoryController::class, 'adjust']
        )->whereNumber('productId')->middleware('throttle:20,1');

        Route::get('/products/{productId}/stock-movements',
            [\App\Http\Controllers\Api\InventoryController::class, 'history']
        )->whereNumber('productId');
    });

Route::middleware('auth:sanctum')
    ->prefix('shops/{shopId}')
    ->whereNumber('shopId')
    ->group(function () {
        Route::get('/sales',
            [\App\Http\Controllers\Api\SaleController::class, 'index']
        );

        Route::post('/sales',
            [\App\Http\Controllers\Api\SaleController::class, 'store']
        )->middleware('throttle:20,1');

        Route::get('/sales/{saleId}',
            [\App\Http\Controllers\Api\SaleController::class, 'show']
        )->whereNumber('saleId');
    });