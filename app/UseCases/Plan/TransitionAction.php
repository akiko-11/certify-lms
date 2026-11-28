<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanOperationConflictException;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TransitionAction
{
    public function __invoke(User $actor, Plan $plan, PlanStatus $target): void
    {
        DB::transaction(function () use ($actor, $plan, $target): void {
            $current = Plan::query()->lockForUpdate()->findOrFail($plan->getKey());
            if (! $current->canTransitionTo($target)) {
                throw new PlanOperationConflictException(match ($target) {
                    PlanStatus::Published => '下書きのプランのみ公開できます。',
                    PlanStatus::Archived => '公開中のプランのみアーカイブできます。',
                    PlanStatus::Draft => 'アーカイブされたプランのみ下書きに戻せます。',
                });
            }

            if ($target === PlanStatus::Published && ! PlanBasicInformationHelper::isValid($current)) {
                throw new PlanOperationConflictException('プランの基本情報を確認してから公開してください。');
            }

            $current->update([
                'status' => $target,
                'updated_by_user_id' => $actor->id,
            ]);
        });
    }
}
