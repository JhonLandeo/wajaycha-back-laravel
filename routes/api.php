<?php

use App\Http\Controllers\AuthJWT\JWTAuthController;
use App\Http\Controllers\ExpensesController;
use App\Http\Middleware\JwtMiddleware;
use Illuminate\Support\Facades\Route;

Route::post('register', [JWTAuthController::class, 'register']);
Route::post('login', [JWTAuthController::class, 'login']);

Route::middleware([JwtMiddleware::class])->group(function () {
    Route::get('kpi-data', [ExpensesController::class, 'kpiData']);
    Route::get('top-data', [ExpensesController::class, 'topFiveData']);
    Route::get('weekly-data', [ExpensesController::class, 'getWeeklyData']);
    Route::get('hourly-data', [ExpensesController::class, 'getHourlyData']);
    Route::get('monthly-data', [ExpensesController::class, 'getMonthlyData']);
    Route::post('import', [ExpensesController::class, 'import']);
});
