<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 管理者向け面談パック一覧を検索条件付きで取得する。
 */
final class IndexAction
{
    private const PER_PAGE = 20;

    public function __invoke(
        ?string $keyword = null,
        ?MeetingPackStatus $status = null,
        int $page = 1,
    ): LengthAwarePaginator {
        $query = MeetingPack::query();

        if ($keyword !== null && $keyword !== '') {
            $escapedKeyword = strtr($keyword, [
                '!' => '!!',
                '%' => '!%',
                '_' => '!_',
            ]);

            $query->whereRaw(
                "name LIKE ? ESCAPE '!'",
                ['%'.$escapedKeyword.'%'],
            );
        }

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $query->orderByRaw(
            'CASE WHEN status = ? THEN 0 ELSE 1 END ASC',
            [MeetingPackStatus::Published->value],
        )
            ->ordered()
            ->orderByDesc('id');

        // オフセットが PHP の整数範囲を超えるページも空一覧として返す。
        $plans = $page - 1 > intdiv(PHP_INT_MAX, self::PER_PAGE)
            ? new LengthAwarePaginator([], $query->count(), self::PER_PAGE, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()])
            : $query->paginate(self::PER_PAGE, page: $page);

        return $plans->appends(['keyword' => $keyword, 'status' => $status?->value]);
    }
}
