<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックを下書きとして新規作成するユースケース。
 */
final class StoreAction
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
        array $validated,
    ): MeetingPack {
        return DB::transaction(fn () => MeetingPack::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'meeting_count' => (int) $validated['meeting_count'],
            'price' => (int) $validated['price'],
            'stripe_price_id' => $validated['stripe_price_id'] ?? null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'status' => MeetingPackStatus::Draft,
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
        ]));
    }
}
