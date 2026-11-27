<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackNotDeletableException;
use App\Models\MeetingPack;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックを物理削除するユースケース。
 */
final class DestroyAction
{
    public function __invoke(MeetingPack $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $lockedPlan = MeetingPack::query()
                ->lockForUpdate()
                ->findOrFail($plan->getKey());

            if ($lockedPlan->status === MeetingPackStatus::Published) {
                throw MeetingPackNotDeletableException::forPublished();
            }

            $lockedPlan->delete();
        });
    }
}
