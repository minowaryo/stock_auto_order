<?php

use App\Http\Controllers\CandidateCheckController;
use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\HoldingDetailController;
use App\Http\Controllers\HoldingListController;
use App\Http\Controllers\ImportSummaryReportController;
use App\Http\Controllers\MarketIndicatorController;
use App\Http\Controllers\NewCandidateController;
use App\Http\Controllers\SectorDashboardController;
use App\Http\Controllers\SignalListController;
use App\Http\Controllers\WatchedThemeController;
use App\Livewire\Auth\Login;
use App\Livewire\CsvImport\Upload;
use App\Livewire\ImportSummaryReport\Show;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', Login::class)->name('login');
Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/login');
})->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/csv-import', Upload::class);
    Route::get('/import-batches/{importBatch}/summary-report', Show::class);
});

// JSON API（フロントエンドのLivewireページはこれらをHTTP経由で呼ばず、
// 同じApp\Actions\**を直接呼び出す。既存の自動テスト資産をそのまま
// 維持するため、/api配下に退避するだけでロジック・ミドルウェアは
// 変更しない〔stock_auto_order-frontend-implementation-phase.md Phase0〕。
Route::prefix('api')->middleware('auth')->group(function () {
    Route::post('/csv-import', [CsvImportController::class, 'store']);
    Route::get('/holdings', [HoldingListController::class, 'index']);
    Route::get('/holdings/{holding}', [HoldingDetailController::class, 'show']);
    Route::post('/holdings/{holding}/memos', [HoldingDetailController::class, 'storeMemo']);
    Route::get('/import-batches/{importBatch}/summary-report', [ImportSummaryReportController::class, 'show']);
    Route::get('/signals', [SignalListController::class, 'index']);
    Route::post('/watched-themes', [WatchedThemeController::class, 'store']);
    Route::get('/watched-themes', [WatchedThemeController::class, 'index']);
    Route::get('/new-candidates', [NewCandidateController::class, 'index']);
    Route::get('/sector-dashboard', [SectorDashboardController::class, 'index']);
    Route::get('/candidate-check', [CandidateCheckController::class, 'show']);
    Route::post('/candidate-check/watch-records', [CandidateCheckController::class, 'storeWatchRecord']);
    Route::get('/market-indicators', [MarketIndicatorController::class, 'index']);
});
