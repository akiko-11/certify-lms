<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックの基本情報を更新するユースケース。
 */
final class UpdateAction
{
    /**
     * @param array{
     *     name: string,
     *     description?: string|null,
     *     meeting_count: int|numeric-string,
     *     price: int|numeric-string,
     *     stripe_price_id?: string|null,
     *     sort_order?: int|numeric-string|null
     * } $validated
     */
    public function __invoke(
        User $actor,
        MeetingPack $plan,
        array $validated,
    ): MeetingPack {
        return DB::transaction(function () use (
            $actor,
            $plan,
            $validated,
        ): MeetingPack {
            $lockedPlan = MeetingPack::query()
                ->lockForUpdate()
                ->findOrFail($plan->getKey());

            $lockedPlan->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'meeting_count' => (int) $validated['meeting_count'],
                'price' => (int) $validated['price'],
                'stripe_price_id' => $validated['stripe_price_id'] ?? null,
                'sort_order' => (int) ($validated['sort_order'] ?? 0),
                'updated_by_user_id' => $actor->id,
            ]);

            return $lockedPlan;
        });
    }
}
