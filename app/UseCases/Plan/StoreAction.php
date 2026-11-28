<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;

final class StoreAction
{
    public function __invoke(User $actor, array $validated): Plan
    {
        return Plan::create([
            ...PlanBasicInformationHelper::attributesForSave($validated),
            'status' => PlanStatus::Draft,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
        ]);
    }
}
