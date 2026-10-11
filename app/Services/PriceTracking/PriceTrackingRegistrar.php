<?php

namespace App\Services\PriceTracking;

use App\Models\Holding;
use App\Models\SignalOccurrence;
use App\Models\TradeExecution;
use Illuminate\Support\Collection;

/**
 * Registers / extends the tracking deadline of holdings from their last sale
 * and last signal week (UC-018, ADR-0024 D1).
 */
class PriceTrackingRegistrar
{
    public function __construct(private readonly PriceTrackingTargetUpdater $targets) {}

    /**
     * @param  Collection<int, Holding>  $holdings
     */
    public function register(Collection $holdings): void
    {
        $ids = $holdings->modelKeys();

        $lastSells = TradeExecution::query()
            ->whereIn('holding_id', $ids)
            ->where('kind', 'sell')
            ->groupBy('holding_id')
            ->selectRaw('holding_id, MAX(trade_date) AS last_date') // aggregate only, no user input
            ->pluck('last_date', 'holding_id');

        $lastSignals = SignalOccurrence::query()
            ->whereIn('holding_id', $ids)
            ->groupBy('holding_id')
            ->selectRaw('holding_id, MAX(observed_week) AS last_week') // aggregate only, no user input
            ->pluck('last_week', 'holding_id');

        foreach ($holdings as $holding) {
            $this->targets->register($holding, $lastSells[$holding->id] ?? null, $lastSignals[$holding->id] ?? null);
        }
    }
}
