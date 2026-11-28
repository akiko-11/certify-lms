<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;
use Illuminate\Support\Facades\DB;

final class UpdateAction
{
    public function __invoke(EnrollmentGoal $goal, array $validated): void
    {
        DB::transaction(function () use ($goal, $validated): void {
            $goal->lockForMutation()->update($validated);
        });
    }
}
