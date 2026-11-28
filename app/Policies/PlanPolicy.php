<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\User;
use App\UseCases\Plan\PlanBasicInformationHelper;

class PlanPolicy
{
    public function manage(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, Plan $plan): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Plan $plan): bool
    {
        return $this->manage($user);
    }

    public function publish(User $user, Plan $plan): bool
    {
        return $this->manage($user)
            && $plan->canTransitionTo(PlanStatus::Published)
            && PlanBasicInformationHelper::isValid($plan);
    }

    public function archive(User $user, Plan $plan): bool
    {
        return $this->manage($user) && $plan->canTransitionTo(PlanStatus::Archived);
    }

    public function unarchive(User $user, Plan $plan): bool
    {
        return $this->manage($user) && $plan->canTransitionTo(PlanStatus::Draft);
    }

    public function delete(User $user, Plan $plan): bool
    {
        return $this->manage($user) && $plan->deletionBlockedReason() === null;
    }
}
