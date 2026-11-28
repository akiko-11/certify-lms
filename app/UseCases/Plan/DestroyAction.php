<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Exceptions\Plan\PlanOperationConflictException;
use App\Models\Plan;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DestroyAction
{
    public function __invoke(Plan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $current = Plan::query()->lockForUpdate()->findOrFail($plan->getKey());
            $reason = $current->deletionBlockedReason();

            if ($reason !== null) {
                throw new PlanOperationConflictException($reason);
            }

            try {
                $current->delete();
            } catch (QueryException $exception) {
                $detail = $exception->errorInfo[2] ?? '';

                // MySQLの親行削除制約のうち、プランを参照する外部キーだけを扱う。
                if (($exception->errorInfo[0] ?? null) === '23000'
                    && ($exception->errorInfo[1] ?? null) === 1451
                    && (str_contains($detail, '`users_plan_id_foreign`') || str_contains($detail, '`user_plan_logs_plan_id_foreign`'))) {
                    throw new PlanOperationConflictException('関連データが存在するため、プランを削除できません。', $exception);
                }

                throw $exception;
            }
        });
    }
}
