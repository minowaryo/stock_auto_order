@php
    $priceHistory = $detail['price_history'];
    $prices = array_column($priceHistory, 'close_price');
    $minPrice = count($prices) ? min($prices) : 0;
    $maxPrice = count($prices) ? max($prices) : 1;
    $range = ($maxPrice - $minPrice) ?: 1;
    $pointCount = count($priceHistory);
    $lastPrice = $pointCount ? $priceHistory[$pointCount - 1]['close_price'] : null;

    $chartCoordinates = [];
    foreach ($priceHistory as $i => $point) {
        $x = $pointCount > 1 ? ($i / ($pointCount - 1)) * 300 : 150;
        $y = 100 - (($point['close_price'] - $minPrice) / $range) * 100;
        $chartCoordinates[] = ['x' => $x, 'y' => $y];
    }
    $polylinePoints = implode(' ', array_map(fn ($c) => "{$c['x']},{$c['y']}", $chartCoordinates));

    $chartPeriods = ['1y' => '1年', '3y' => '3年', '5y' => '5年', '10y' => '10年'];

    // Numeric display formatting helpers (null-safe; null values are left as
    // null so the existing `?? '取得不可'` fallback in the view keeps handling them).
    $fmtPrice = fn ($value) => $value === null ? null : number_format((float) $value, 2);
    $fmtVolume = fn ($value) => $value === null ? null : number_format((float) $value, 0);
    $fmtUnsignedPercent = fn ($value) => $value === null ? null : number_format((float) $value, 1).'%';
    $fmt1Decimal = fn ($value) => $value === null ? null : number_format((float) $value, 1);
    $fmt2Decimal = fn ($value) => $value === null ? null : number_format((float) $value, 2);

    $technicalFields = [
        'RSI' => $fmt1Decimal($detail['rsi']),
        'MACD' => $fmt2Decimal($detail['macd']),
        'ボリンジャーバンド（上限）' => $fmtPrice($detail['bollinger_band']['bb_upper']),
        'ボリンジャーバンド（下限）' => $fmtPrice($detail['bollinger_band']['bb_lower']),
        'MA20' => $fmtPrice($detail['ma20']),
        'MA75' => $fmtPrice($detail['ma75']),
        '出来高' => $fmtVolume($detail['volume']),
        '出来高MA20' => $fmtVolume($detail['volume_ma20']),
        '52週高値' => $fmtPrice($detail['week52_high']),
        '52週安値' => $fmtPrice($detail['week52_low']),
        '相対強度（対市場）' => $fmtUnsignedPercent($detail['relative_strength_vs_market']),
        '相対強度（対セクター）' => $fmtUnsignedPercent($detail['relative_strength_vs_sector']),
    ];

    $fundamentalFields = [
        'PER' => $fmt1Decimal($detail['per']),
        // NOTE: PBR is rendered with the same 1-decimal rule as PER/RSI, not
        // MACD's 2-decimal rule, because tests/Feature/HoldingDetailTest.php
        // (Gate4-approved, not editable) asserts '<dd>1.3</dd>' for a pbr
        // fixture value of 1.3 (stored as "1.30" via the decimal:2 cast) —
        // 2 decimals would render "1.30" and fail that exact-match assertion.
        'PBR' => $fmt1Decimal($detail['pbr']),
        'ROE' => $fmtUnsignedPercent($detail['roe']),
        '売上成長率' => $fmtUnsignedPercent($detail['revenue_growth']),
        '自己資本比率' => $fmtUnsignedPercent($detail['equity_ratio']),
        '配当利回り' => $fmtUnsignedPercent($detail['dividend_yield']),
        'EPS成長率' => $fmtUnsignedPercent($detail['eps_growth']),
        'PEGレシオ' => $fmt2Decimal($detail['peg_ratio']),
    ];
@endphp
<div>
    <x-page-header
        :title="$detail['symbol_name']"
        :caption="$detail['symbol_code']"
        back-to="/holdings"
        back-label="保有一覧に戻る"
    />

    <x-card>
        <div class="grid grid-cols-2 gap-2">
            <x-stat-box label="取得単価">{{ $detail['average_cost'] !== null ? number_format($detail['average_cost'], 2) : '取得不可' }}</x-stat-box>
            <x-stat-box label="現在値">{{ $lastPrice !== null ? number_format($lastPrice, 2) : '取得不可' }}</x-stat-box>
        </div>
    </x-card>

    <x-card>
        <h2 class="text-base font-semibold mb-2">株価推移</h2>

        <div role="img" aria-label="{{ $detail['symbol_name'] }}の株価推移チャート（{{ $pointCount }}件のデータ）">
            <svg viewBox="0 0 300 100" class="w-full h-40">
                @if ($pointCount > 0)
                    <polyline
                        fill="none"
                        stroke="#2563eb"
                        stroke-width="2"
                        points="{{ $polylinePoints }}"
                    />
                    @foreach ($chartCoordinates as $coordinate)
                        <circle data-testid="price-chart-point" cx="{{ $coordinate['x'] }}" cy="{{ $coordinate['y'] }}" r="2" fill="#2563eb" />
                    @endforeach
                @endif
            </svg>
        </div>

        <div class="flex gap-2 mt-2">
            @foreach ($chartPeriods as $value => $label)
                <button
                    type="button"
                    wire:click="$set('chartPeriod', '{{ $value }}')"
                    class="px-3 py-1 text-[13px] rounded border {{ $chartPeriod === $value ? 'border-primary text-primary' : 'border-app-border text-text-secondary' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </x-card>

    <x-card>
        <h2 class="text-base font-semibold mb-2">利確シグナル判定</h2>
        <x-badge :variant="$detail['signal_result'] === '利確検討' ? 'warning' : 'neutral'">
            {{ $detail['signal_result'] }}
        </x-badge>
        <p class="text-[13px] mt-2">{{ $detail['signal_reason'] }}</p>
    </x-card>

    <div class="grid grid-cols-2 gap-4">
        <x-card>
            <h2 class="text-base font-semibold mb-2">テクニカル指標</h2>
            <dl class="text-[13px]">
                @foreach ($technicalFields as $label => $value)
                    <div class="flex justify-between py-1 border-b border-app-border last:border-b-0">
                        <dt class="text-text-secondary">{{ $label }}</dt>
                        <dd>{{ $value ?? '取得不可' }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-card>

        <x-card>
            <h2 class="text-base font-semibold mb-2">ファンダメンタルズ指標</h2>
            <dl class="text-[13px]">
                @foreach ($fundamentalFields as $label => $value)
                    <div class="flex justify-between py-1 border-b border-app-border last:border-b-0">
                        <dt class="text-text-secondary">{{ $label }}</dt>
                        <dd>{{ $value ?? '取得不可' }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-card>
    </div>

    <x-card>
        <h2 class="text-base font-semibold mb-2">メモ</h2>

        <form wire:submit.prevent="saveMemo" class="mb-4">
            <textarea
                wire:model.blur="newMemoBody"
                class="w-full border border-app-border rounded p-2 text-[13px]"
                rows="3"
                placeholder="メモを入力"
            ></textarea>
            @error('newMemoBody')
                <p class="text-danger text-xs mt-1">{{ $message }}</p>
            @enderror
            <button type="submit" class="mt-2 px-3 py-1 text-[13px] rounded bg-primary text-white">
                メモを保存
            </button>
        </form>

        @if (empty($detail['memo_history']))
            <x-empty-state>メモはまだありません</x-empty-state>
        @else
            <ul class="text-[13px] space-y-2">
                @foreach ($detail['memo_history'] as $memo)
                    <li class="border-b border-app-border pb-2 last:border-b-0">
                        <div class="text-text-secondary text-xs">{{ $memo['recorded_at'] }}</div>
                        <div>{{ $memo['body'] }}</div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
</div>
