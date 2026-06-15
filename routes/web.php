<?php

use App\Http\Controllers\Utilities\DataTransferController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/utilities');

Route::get('/utilities', [DataTransferController::class, 'index'])->name('utilities.transfer');
Route::post('/utilities/test-connection', [DataTransferController::class, 'testConnection'])->name('utilities.test-connection');
Route::post('/utilities/run', [DataTransferController::class, 'runConversion'])->name('utilities.run');
