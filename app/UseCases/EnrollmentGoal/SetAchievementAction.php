<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

final class SetAchievementAction
{
    public function __invoke(EnrollmentGoal $goal, bool $achieved): bool
    {
        return DB::transaction(function () use ($goal, $achieved): bool {
            $current = $goal->lockForMutation();

            if ($current->isAchieved() === $achieved) {
                return false;
            }

            $current->achieved_at = $achieved ? now() : null;
            $current->save();

            return true;
        });
    }
}
