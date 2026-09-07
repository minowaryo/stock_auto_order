<?php

namespace App\Actions\Watchlist;

use App\Actions\Watchlist\Support\FavoriteImportResult;
use App\Exceptions\Import\CsvStructureException;
use App\Models\Holding;
use App\Models\WatchlistItem;
use App\Services\Import\RakutenFavoriteCsvParser;
use App\Services\Import\Support\ParsedFavoriteRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * UC-012「基本フロー（CSV取込）」(F-012 / ADR-0013): decode the uploaded
 * 楽天証券 favorites CSV, parse it, and register each in-scope symbol into
 * the銘柄マスタ (`holdings`, find-or-create — data-model.md「作成経路②」) and
 * the watchlist (`watchlist_items`, upsert keyed by holding_id).
 *
 * Re-import is additive: rows absent from the new CSV are not deleted, and
 * `is_starred` is preserved; only `folder_name` / `exchange_label` /
 * `last_seen_in_csv_at` are refreshed.
 *
 * Never touches `snapshots` / `holding_snapshots` / `signals` / `buy_signals`.
 * The bulk indicator refresh is a separate step (Cycle 3).
 */
class ImportFavoriteCsvAction
{
    public function __construct(
        private readonly RakutenFavoriteCsvParser $parser,
    ) {}

    public function execute(UploadedFile $file): FavoriteImportResult
    {
        try {
            $parsed = $this->parser->parse($this->decode($file));
        } catch (CsvStructureException $e) {
            return FavoriteImportResult::failure($e->getMessage());
        }

        $now = now();

        DB::transaction(function () use ($parsed, $now) {
            foreach ($parsed->rows as $row) {
                $this->registerRow($row, $now);
            }
        });

        return FavoriteImportResult::success(count($parsed->rows), $parsed->skippedCount);
    }

    private function registerRow(ParsedFavoriteRow $row, \DateTimeInterface $seenAt): void
    {
        $holding = Holding::firstOrCreate(
            ['symbol_code' => $row->code, 'market' => $row->market],
            [
                'instrument_type' => $row->instrumentType,
                'symbol_name' => $row->name,
                'first_detected_at' => $seenAt,
            ],
        );

        WatchlistItem::updateOrCreate(
            ['holding_id' => $holding->id],
            [
                'folder_name' => $row->folderName,
                'exchange_label' => $row->exchangeLabel,
                'source' => 'rakuten_favorites_csv',
                'last_seen_in_csv_at' => $seenAt,
            ],
        );
    }

    /**
     * 楽天証券CSVはCP932。App\Actions\Import\ImportCsvAction::decode() と同じ。
     */
    private function decode(UploadedFile $file): string
    {
        return mb_convert_encoding($file->get(), 'UTF-8', 'SJIS-win');
    }
}
