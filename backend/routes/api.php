<?php

use App\Http\Controllers\Api\v1\QueryController;
use App\Http\Controllers\Api\v1\HistoryController;
use App\Http\Controllers\Api\v1\SchemaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/query', QueryController::class);
    Route::get('/history', HistoryController::class);
    Route::get('/schema', SchemaController::class);
});
