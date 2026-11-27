<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackInvalidTransitionException;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックの公開状態を変更するユースケース。
 */
final class TransitionAction
{
    public function __invoke(
        User $actor,
        MeetingPack $plan,
        MeetingPackStatus $targetStatus,
    ): MeetingPack {
        return DB::transaction(function () use (
            $actor,
            $plan,
            $targetStatus,
        ): MeetingPack {
            $lockedPlan = MeetingPack::query()
                ->lockForUpdate()
                ->findOrFail($plan->getKey());

            $requiredStatus = match ($targetStatus) {
                MeetingPackStatus::Published => MeetingPackStatus::Draft,
                MeetingPackStatus::Archived => MeetingPackStatus::Published,
                MeetingPackStatus::Draft => MeetingPackStatus::Archived,
            };

            if ($lockedPlan->status !== $requiredStatus) {
                throw match ($targetStatus) {
                    MeetingPackStatus::Published => MeetingPackInvalidTransitionException::forPublish(),
                    MeetingPackStatus::Archived => MeetingPackInvalidTransitionException::forArchive(),
                    MeetingPackStatus::Draft => MeetingPackInvalidTransitionException::forUnarchive(),
                };
            }

            $lockedPlan->update([
                'status' => $targetStatus->value,
                'updated_by_user_id' => $actor->id,
            ]);

            return $lockedPlan;
        });
    }
}
