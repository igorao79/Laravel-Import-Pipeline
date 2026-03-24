<?php

use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('imports', ImportController::class)->only(['index', 'store', 'show']);
    Route::post('imports/{import}/retry', [ImportController::class, 'retry'])->name('imports.retry');
});
