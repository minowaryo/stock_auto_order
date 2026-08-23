<?php

namespace App\Actions\Candidate;

use App\Services\Candidate\NewCandidateFinder;

/**
 * UC-008 (新規投資候補レコメンド・軽量版候補一覧): thin Action delegating to
 * NewCandidateFinder (same pattern as ShowSignalListAction).
 */
class ShowNewCandidateListAction
{
    public function __construct(private readonly NewCandidateFinder $finder) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(): array
    {
        return $this->finder->find();
    }
}
