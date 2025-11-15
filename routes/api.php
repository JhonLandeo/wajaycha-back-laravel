<?php

use App\Http\Controllers\AuthJWT\JWTAuthController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CategoryRuleController;
use App\Http\Controllers\ChatGptController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DetailsController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ParetoClassificationController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TagsController;
use App\Http\Controllers\TransactionsController;
use App\Http\Controllers\TransactionYapeController;
use App\Http\Middleware\JwtMiddleware;
use Illuminate\Support\Facades\Route;

Route::post('register', [JWTAuthController::class, 'register']);
Route::post('login', [JWTAuthController::class, 'login']);

Route::middleware([JwtMiddleware::class])->group(function () {
    Route::post('logout', [JWTAuthController::class, 'logout']);
    Route::post('kpi-data', [DashboardController::class, 'kpiData']);
    Route::post('top-data', [DashboardController::class, 'topFiveData']);
    Route::post('weekly-data', [DashboardController::class, 'getWeeklyData']);
    Route::post('hourly-data', [DashboardController::class, 'getHourlyData']);
    Route::post('monthly-data', [DashboardController::class, 'getMonthlyData']);
    Route::post('import-yape', [TransactionYapeController::class, 'import']);
    Route::post('transaction-by-category', [DashboardController::class, 'getTransactionByCategory']);
    Route::post('import-yape-pdf', [ImportController::class, 'storeYape']);

    Route::resource('pareto-classification', ParetoClassificationController::class);
    Route::resource('categories', CategoryController::class);
    Route::resource('transactions', TransactionsController::class);
    Route::resource('imports', ImportController::class);
    Route::resource('details', DetailsController::class);
     Route::resource('tags', TagsController::class);

    Route::post('update-detail-for-name', [DetailsController::class, 'updateNameCommon']);
    Route::get('get-summary-by-category', [TransactionsController::class, 'getSummaryByCategory']);

    Route::post('chat', [ChatGptController::class, 'chat']);
    Route::post('extract-pdf-data', [PdfController::class, 'extractData']);
    Route::post('export-transactions', [TransactionsController::class, 'exportTransaction']);

    Route::get('get-bank', [ImportController::class, 'getBank']);
    Route::get('get-service', [ImportController::class, 'getService']);
    Route::get('/imports/{id}/download', [ImportController::class, 'download']);

    Route::post('/yape/find-suggestions', [TransactionYapeController::class, 'findSuggestions'])->name('yape.findSuggestions');

    Route::prefix('categories')->group(function () {
        Route::get('/{category}/rules', [CategoryRuleController::class, 'getRules']);

        Route::get('/{category}/suggestions', [CategoryRuleController::class, 'getSuggestions']);

        Route::post('/{category}/sync', [CategoryRuleController::class, 'syncRule']);
    });
});
