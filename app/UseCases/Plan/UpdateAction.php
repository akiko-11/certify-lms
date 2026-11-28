<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateAction
{
    public function __invoke(User $actor, Plan $plan, array $validated): Plan
    {
        return DB::transaction(function () use ($actor, $plan, $validated): Plan {
            $current = Plan::query()->lockForUpdate()->findOrFail($plan->getKey());
            $current->fill([
                ...PlanBasicInformationHelper::attributesForSave($validated),
                'updated_by_user_id' => $actor->id,
            ]);
            $current->setUpdatedAt($current->freshTimestamp())->save();

            return $current;
        });
    }
}
