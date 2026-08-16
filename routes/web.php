<?php

use App\Http\Controllers\CsvImportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->post('/csv-import', [CsvImportController::class, 'store']);
