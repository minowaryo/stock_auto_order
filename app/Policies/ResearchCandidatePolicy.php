<?php

namespace App\Policies;

use App\Models\ResearchCandidate;
use App\Models\User;

class ResearchCandidatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ResearchCandidate $candidate): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ResearchCandidate $candidate): bool
    {
        return true;
    }

    public function delete(User $user, ResearchCandidate $candidate): bool
    {
        return true;
    }

    public function restore(User $user, ResearchCandidate $candidate): bool
    {
        return true;
    }
}
