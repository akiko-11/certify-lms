<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Models\Plan;
use App\Services\MeetingQuotaService;

final class ShowAction
{
    public function __construct(private readonly MeetingQuotaService $quotas) {}

    /** @return array{plan: Plan, remainingQuotas: array<string, int>} */
    public function __invoke(Plan $plan): array
    {
        $plan->load([
            'createdBy',
            'updatedBy',
            'inProgressStudents' => fn ($students) => $students->orderByDesc('plan_started_at')->orderByDesc('id'),
        ]);

        return [
            'plan' => $plan,
            'remainingQuotas' => $this->quotas->remainingForUsers($plan->inProgressStudents),
        ];
    }
}
