<?php

use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\HoldingDetailController;
use App\Http\Controllers\HoldingListController;
use App\Http\Controllers\ImportSummaryReportController;
use App\Http\Controllers\NewCandidateController;
use App\Http\Controllers\SectorDashboardController;
use App\Http\Controllers\SignalListController;
use App\Http\Controllers\WatchedThemeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->post('/csv-import', [CsvImportController::class, 'store']);
Route::middleware('auth')->get('/holdings', [HoldingListController::class, 'index']);
Route::middleware('auth')->get('/holdings/{holding}', [HoldingDetailController::class, 'show']);
Route::middleware('auth')->post('/holdings/{holding}/memos', [HoldingDetailController::class, 'storeMemo']);
Route::middleware('auth')->get('/import-batches/{importBatch}/summary-report', [ImportSummaryReportController::class, 'show']);
Route::middleware('auth')->get('/signals', [SignalListController::class, 'index']);
Route::middleware('auth')->post('/watched-themes', [WatchedThemeController::class, 'store']);
Route::middleware('auth')->get('/watched-themes', [WatchedThemeController::class, 'index']);
Route::middleware('auth')->get('/new-candidates', [NewCandidateController::class, 'index']);
Route::middleware('auth')->get('/sector-dashboard', [SectorDashboardController::class, 'index']);
