<?php

use App\Http\Controllers\AuthJWT\JWTAuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CategoryRuleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DetailsController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ParetoClassificationController;
use App\Http\Controllers\Reconciliation\ReconciliationCandidateController;
use App\Http\Controllers\TagsController;
use App\Http\Controllers\TransactionsController;
use App\Http\Controllers\TransactionYapeController;
use App\Http\Middleware\JwtMiddleware;
use Illuminate\Support\Facades\Route;

Route::post('telegram/webhook', [\App\Http\Controllers\Capture\TelegramWebhookController::class, 'receive'])
    ->middleware(\App\Http\Middleware\VerifyTelegramSecretToken::class);

// Estas dos son las unicas rutas sin autenticar que crean o entregan credenciales,
// y el grupo `api` de Laravel 11 no trae throttle salvo que `bootstrap/app.php`
// llame a `throttleApi()`, cosa que no hace. Sin esto no hay ningun limite.
//
// El limite por IP es la mitad que le falta al contador por cuenta de LoginRequest:
// ese contador incluye el email en su clave, asi que probar una sola contrasena
// contra cien direcciones distintas nunca lo activa. Las dos capas atacan cosas
// diferentes y ninguna reemplaza a la otra.
Route::post('register', [JWTAuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('login', [JWTAuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware([JwtMiddleware::class])->group(function () {
    Route::post('logout', [JWTAuthController::class, 'logout']);
    Route::post('channel-link-token', [\App\Http\Controllers\Capture\ChannelLinkController::class, 'issue'])
        ->middleware('throttle:6,1');
    Route::get('channel-identities', [\App\Http\Controllers\Capture\ChannelIdentityController::class, 'index']);
    Route::post('kpi-data', [DashboardController::class, 'kpiData']);
    Route::post('top-data', [DashboardController::class, 'topFiveData']);
    Route::post('weekly-data', [DashboardController::class, 'getWeeklyData']);
    Route::post('monthly-data', [DashboardController::class, 'getMonthlyData']);
    Route::post('import-yape', [TransactionYapeController::class, 'import']);
    Route::post('transaction-by-category', [DashboardController::class, 'getTransactionByCategory']);

    Route::resource('pareto-classification', ParetoClassificationController::class);
    Route::resource('categories', CategoryController::class);
    Route::patch('categories/{category}/pareto', [CategoryController::class, 'patchPareto']);
    Route::resource('transactions', TransactionsController::class);
    Route::resource('imports', ImportController::class);
    Route::resource('details', DetailsController::class);
    Route::resource('tags', TagsController::class);

    // Duplicados entre fuentes. Los pares separados por segundos los unifica el
    // sistema; los dudosos se preguntan. `auto-merged` es lo que hace legitima esa
    // decision automatica: lo unificado queda a la vista y `undo` lo revierte.
    Route::get('reconciliation-candidates', [ReconciliationCandidateController::class, 'index']);
    Route::get('reconciliation-candidates/auto-merged', [ReconciliationCandidateController::class, 'autoMerged']);
    Route::post('reconciliation-candidates/{candidate}/confirm', [ReconciliationCandidateController::class, 'confirm']);
    Route::post('reconciliation-candidates/{candidate}/reject', [ReconciliationCandidateController::class, 'reject']);
    Route::post('reconciliation-candidates/{candidate}/undo', [ReconciliationCandidateController::class, 'undo']);

    Route::get('all-categories', [CategoryController::class, 'all']);
    Route::get('all-pareto-classification', [ParetoClassificationController::class, 'all']);
    Route::get('pareto-classification/{pareto_classification}/categories', [ParetoClassificationController::class, 'categories']);
    Route::post('update-detail-for-name', [DetailsController::class, 'updateNameCommon']);
    Route::get('get-summary-by-category', [TransactionsController::class, 'getSummaryByCategory']);

    Route::get('get-bank', [ImportController::class, 'getBank']);
    Route::get('get-service', [ImportController::class, 'getService']);
    Route::get('/imports/{id}/download', [ImportController::class, 'download']);

    Route::prefix('categories')->group(function () {
        Route::get('/{category}/rules', [CategoryRuleController::class, 'getRules']);

        Route::get('/{category}/suggestions', [CategoryRuleController::class, 'getSuggestions']);

        Route::post('/{category}/sync', [CategoryRuleController::class, 'syncRule']);
    });
});
