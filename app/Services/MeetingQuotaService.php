<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MeetingQuotaTransactionType;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * 面談回数の残数集計と履歴取得を提供するステートレス Service。
 *
 * 残数集計は `User.max_meetings`(初期付与 + コース延長付与の累計) と
 * `MeetingQuotaTransaction.amount` の和で求める。`granted_initial` は `max_meetings` と
 * 二重カウント防止のため SUM 集計から除外する。
 *
 * 履歴取得は受講生の面談履歴画面と admin のユーザー詳細画面から呼ばれる想定で、
 * 関連の Meeting / Payment / 管理者を Eager Loading して N+1 を抑制する。
 */
final class MeetingQuotaService
{
    private const BALANCE_TRANSACTION_TYPES = [
        MeetingQuotaTransactionType::Consumed,
        MeetingQuotaTransactionType::Refunded,
        MeetingQuotaTransactionType::Purchased,
        MeetingQuotaTransactionType::AdminGrant,
    ];

    /**
     * 受講生の残面談回数を 1 クエリで集計する。
     */
    public function remaining(User $user): int
    {
        $sum = (int) MeetingQuotaTransaction::query()
            ->where('user_id', $user->id)
            ->whereIn('type', self::BALANCE_TRANSACTION_TYPES)
            ->sum('amount');

        return $user->max_meetings + $sum;
    }

    /**
     * @param Collection<int, User> $users
     *
     * @return array<string, int> ユーザーIDごとの残面談回数
     */
    public function remainingForUsers(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $sums = MeetingQuotaTransaction::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->whereIn('type', self::BALANCE_TRANSACTION_TYPES)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(amount) AS balance_delta')
            ->pluck('balance_delta', 'user_id');

        return $users->mapWithKeys(fn (User $user): array => [
            $user->id => $user->max_meetings + (int) $sums->get($user->id, 0),
        ])->all();
    }

    /**
     * 受講生の面談履歴を新しい順で paginate する。
     * type フィルタを掛けた場合は該当 type のみ返す。
     *
     * @param ?MeetingQuotaTransactionType $type フィルタ type、null で全件
     *
     * @return LengthAwarePaginator<MeetingQuotaTransaction>
     */
    public function history(
        User $user,
        ?MeetingQuotaTransactionType $type = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        // 追加面談購入 Feature(Payment Model) / 面談予約 Feature(Meeting Model) が未実装の環境では、
        // それぞれの関連の Eager Loading を省略する(belongsTo の class 解決は relation 実行時まで遅延されるため、
        // Eager Loading しない限り未定義クラスを参照しない)。
        $with = ['grantedBy'];
        if (class_exists('App\\Models\\Payment')) {
            $with[] = 'relatedPayment.meetingPack';
        }
        if (class_exists('App\\Models\\Meeting')) {
            $with[] = 'relatedMeeting.enrollment.certification';
        }

        return MeetingQuotaTransaction::query()
            ->where('user_id', $user->id)
            ->with($with)
            ->when($type, fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('occurred_at')
            ->paginate($perPage);
    }
}
