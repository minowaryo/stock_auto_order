@php
    $technicalFields = $checkResult ? [
        'RSI' => $checkResult['rsi'],
        'MACD' => $checkResult['macd'],
        'ボリンジャーバンド（上限）' => $checkResult['bollinger_band']['bb_upper'],
        'ボリンジャーバンド（下限）' => $checkResult['bollinger_band']['bb_lower'],
        'MA20' => $checkResult['ma20'],
        'MA75' => $checkResult['ma75'],
        '出来高' => $checkResult['volume'],
        '出来高MA20' => $checkResult['volume_ma20'],
        '52週高値' => $checkResult['week52_high'],
        '52週安値' => $checkResult['week52_low'],
        '相対強度（対市場）' => $checkResult['relative_strength_vs_market'],
        '相対強度（対セクター）' => $checkResult['relative_strength_vs_sector'],
    ] : [];

    $fundamentalFields = $checkResult ? [
        'PER' => $checkResult['per'],
        'PBR' => $checkResult['pbr'],
        'ROE' => $checkResult['roe'],
        '売上成長率' => $checkResult['revenue_growth'],
        '自己資本比率' => $checkResult['equity_ratio'],
        '配当利回り' => $checkResult['dividend_yield'],
        'EPS成長率' => $checkResult['eps_growth'],
        'PEGレシオ' => $checkResult['peg_ratio'],
    ] : [];

    $watchStatusOptions = ['様子見', '買い時', '次回購入候補', 'リバランス対象'];

    // SectorAllocationCalculator（UC-005）と同一の閾値（40%/70%）で、
    // overlap_rateから表示用ラベルのみを導出する（新規計算式は作らない）。
    $overlapStatus = null;
    if ($checkResult) {
        $overlapRate = (float) $checkResult['overlap_rate'];
        $overlapStatus = match (true) {
            $overlapRate >= 70.0 => '偏り警告',
            $overlapRate >= 40.0 => 'やや偏り',
            default => '健全',
        };
    }
@endphp
<div>
    <x-page-header title="新規投資候補" caption="おすすめ候補の提示（UC-008）と、個別銘柄の重複・分散チェック（UC-006）を1画面にまとめています" />

    <x-card>
        <h2 class="text-base font-semibold mb-2">おすすめ候補</h2>

        @if (empty($candidates))
            <x-empty-state>おすすめ候補はありません</x-empty-state>
        @else
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-text-secondary border-b border-app-border">
                        <th class="py-2 pr-4">銘柄</th>
                        <th class="py-2 pr-4">合致テーマ</th>
                        <th class="py-2 pr-4">財務健全性サマリ</th>
                        <th class="py-2 pr-4">購入額の目安</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($candidates as $candidate)
                        <tr
                            class="border-b border-app-border last:border-b-0 cursor-pointer"
                            data-symbol-code="{{ $candidate['symbol_code'] }}"
                            x-on:click="$wire.symbolCode = '{{ $candidate['symbol_code'] }}'; $el.closest('div').querySelector('#candidate-check-form')?.scrollIntoView({ behavior: 'smooth' })"
                        >
                            <td class="py-2 pr-4">
                                <strong>{{ $candidate['symbol_name'] }}</strong>
                                <span class="text-text-secondary text-xs">{{ $candidate['symbol_code'] }}</span>
                                @if ($candidate['nisa_recommended'])
                                    <x-badge variant="info">NISA推奨</x-badge>
                                @endif
                            </td>
                            <td class="py-2 pr-4">{{ $candidate['matched_theme'] }}</td>
                            <td class="py-2 pr-4 text-text-secondary">
                                {{ $candidate['fundamental_summary'] }}
                                @php $rawFundamental = $rawFundamentals[$candidate['symbol_code']]->fundamentalIndicator ?? null; @endphp
                                @if ($rawFundamental)
                                    <span class="text-xs">（自己資本比率{{ number_format($rawFundamental->equity_ratio, 1) }}%・ROE{{ number_format($rawFundamental->roe, 1) }}%）</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4">¥{{ number_format($candidate['suggested_amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-card>

    <x-card id="candidate-check-form">
        <h2 class="text-base font-semibold mb-2">個別銘柄をチェック</h2>
        <form wire:submit="checkCandidate" class="flex gap-3 items-end">
            <div class="flex-1">
                <label class="block text-[13px] mb-1">証券コード</label>
                <input type="text" wire:model="symbolCode" placeholder="例: 4568" class="w-full border border-app-border rounded p-2 text-[13px]" />
            </div>
            <button type="submit" class="px-3 py-2 text-[13px] rounded bg-primary text-white">重複をチェック</button>
        </form>
    </x-card>

    @if ($checkError)
        <x-card accent="danger">
            <p class="text-danger">{{ $checkError }}</p>
        </x-card>
    @endif

    @if ($checkResult)
        <x-card accent="warning">
            <h2 class="text-base font-semibold mb-2">判定結果</h2>
            <p class="mb-2">
                <strong>{{ $checkResult['symbol_name'] }}</strong>
                <span class="text-text-secondary text-xs">{{ $checkResult['symbol_code'] }}</span>
            </p>
            <dl class="text-[13px]">
                <div class="flex justify-between py-1 border-b border-app-border">
                    <dt class="text-text-secondary">セクター分類</dt>
                    <dd>{{ $checkResult['sector'] }}</dd>
                </div>
                <div class="flex justify-between py-1 border-b border-app-border">
                    <dt class="text-text-secondary">既存保有の同セクター内比率（重複度）</dt>
                    <dd>
                        {{ $checkResult['overlap_rate'] }}%
                        @if ($overlapStatus === '偏り警告')
                            <x-badge variant="danger">{{ $overlapStatus }}</x-badge>
                        @elseif ($overlapStatus === 'やや偏り')
                            <x-badge variant="warning">{{ $overlapStatus }}</x-badge>
                        @else
                            <x-badge variant="success">{{ $overlapStatus }}</x-badge>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between py-1">
                    <dt class="text-text-secondary">分散への影響</dt>
                    <dd>{{ $checkResult['diversification_comment'] }}</dd>
                </div>
            </dl>
        </x-card>

        <x-card>
            <h2 class="text-base font-semibold mb-2">ウォッチステータス・メモ</h2>
            <p class="text-[13px] text-text-secondary mb-3">この銘柄をどう扱うか、自分用の参考情報として記録しておけます。選定の自動判定は行いません</p>

            <form wire:submit="saveWatchRecord" class="mb-4">
                <div class="mb-3">
                    <label class="block text-[13px] mb-1">ウォッチステータス</label>
                    <select wire:model="watchStatus" class="w-full border border-app-border rounded p-2 text-[13px]">
                        <option value="">選択してください</option>
                        @foreach ($watchStatusOptions as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <textarea
                        wire:model="watchMemo"
                        class="w-full border border-app-border rounded p-2 text-[13px]"
                        rows="3"
                        placeholder="例: PERがまだ高いので押し目待ち。半導体の比率が高いので急がなくてよい"
                    ></textarea>
                </div>
                @error('watchRecord')
                    <p class="text-danger text-xs mb-2">{{ $message }}</p>
                @enderror
                <button type="submit" class="px-3 py-2 text-[13px] rounded bg-primary text-white">ウォッチステータス・メモを保存</button>
            </form>

            @if (empty($checkResult['watch_memo_history']))
                <x-empty-state>ウォッチメモはまだありません</x-empty-state>
            @else
                <ul class="text-[13px] space-y-2">
                    @foreach ($checkResult['watch_memo_history'] as $memo)
                        <li class="border-b border-app-border pb-2 last:border-b-0">
                            <div class="text-text-secondary text-xs">
                                {{ $memo['recorded_at'] }}
                                @if ($memo['watch_status'])
                                    <x-badge>{{ $memo['watch_status'] }}</x-badge>
                                @endif
                            </div>
                            <div>{{ $memo['memo'] }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
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
            <h2 class="text-base font-semibold mb-2">過去の業績推移</h2>

            @if (empty($checkResult['historical_performance']))
                <x-empty-state>業績データはありません</x-empty-state>
            @else
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="text-left text-text-secondary border-b border-app-border">
                            <th class="py-2 pr-4">決算期</th>
                            <th class="py-2 pr-4">売上高</th>
                            <th class="py-2 pr-4">営業利益</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($checkResult['historical_performance'] as $statement)
                            <tr class="border-b border-app-border last:border-b-0">
                                <td class="py-2 pr-4">{{ $statement['fiscal_period'] }}</td>
                                <td class="py-2 pr-4">{{ number_format($statement['revenue']) }}</td>
                                <td class="py-2 pr-4">{{ number_format($statement['operating_income']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-card>
    @endif
</div>
