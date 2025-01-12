<?php

use App\Http\Controllers\AuthJWT\JWTAuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChatGptController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DetailsController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\SubcategoriesController;
use App\Http\Controllers\TransactionsController;
use App\Http\Controllers\TransactionYapeController;
use App\Http\Middleware\JwtMiddleware;
use Illuminate\Support\Facades\Route;

Route::post('register', [JWTAuthController::class, 'register']);
Route::post('login', [JWTAuthController::class, 'login']);

Route::middleware([JwtMiddleware::class])->group(function () {
    Route::post('kpi-data', [DashboardController::class, 'kpiData']);
    Route::post('top-data', [DashboardController::class, 'topFiveData']);
    Route::post('weekly-data', [DashboardController::class, 'getWeeklyData']);
    Route::post('hourly-data', [DashboardController::class, 'getHourlyData']);
    Route::post('monthly-data', [DashboardController::class, 'getMonthlyData']);
    Route::post('import-yape', [TransactionYapeController::class, 'import']);
    Route::post('transaction-by-sub-category', [DashboardController::class, 'getTransactionBySubcategory']);

    Route::resource('categories', CategoryController::class);
    Route::resource('subcategories', SubcategoriesController::class);
    Route::resource('transactions', TransactionsController::class);
    Route::resource('imports', ImportController::class);

    Route::post('update-detail-for-name', [DetailsController::class, 'updateNameCommon']);
    Route::get('get-summary-by-sub-category', [TransactionsController::class, 'getSummaryBySubCategory']);

    Route::post('chat', [ChatGptController::class, 'chat']);
    Route::post('extract-pdf-data', [PdfController::class, 'extractData']);
});
