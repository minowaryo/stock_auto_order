<?php

use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\HoldingListController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->post('/csv-import', [CsvImportController::class, 'store']);
Route::middleware('auth')->get('/holdings', [HoldingListController::class, 'index']);
