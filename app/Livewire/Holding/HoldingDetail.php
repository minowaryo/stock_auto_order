<?php

namespace App\Livewire\Holding;

use App\Actions\Holding\SaveHoldingMemoAction;
use App\Actions\Holding\ShowHoldingDetailAction;
use App\Models\Holding;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * UC-003 (銘柄詳細表示): Livewireフルページ版の銘柄詳細画面。
 * ShowHoldingDetailActionは副作用のない参照専用Actionのため、
 * render()のたびに毎回呼び直す（HoldingListと同じ規約）。
 */
#[Layout('components.layouts.app', ['title' => '銘柄詳細'])]
class HoldingDetail extends Component
{
    public Holding $holding;

    public ?string $chartPeriod = '3y';

    public string $newMemoBody = '';

    public function mount(Holding $holding): void
    {
        $this->holding = $holding;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'newMemoBody' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'newMemoBody.max' => 'メモは2000文字以内で入力してください',
        ];
    }

    public function saveMemo(): void
    {
        $this->validate();

        app(SaveHoldingMemoAction::class)->execute($this->holding, $this->newMemoBody);

        $this->newMemoBody = '';
    }

    public function render()
    {
        $detail = app(ShowHoldingDetailAction::class)->execute($this->holding, $this->chartPeriod);

        return view('livewire.holding.holding-detail', [
            'detail' => $detail,
        ]);
    }
}
