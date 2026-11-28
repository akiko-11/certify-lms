<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Pagination\LengthAwarePaginator;

final class IndexAction
{
    private const PER_PAGE = 20;

    /**
     * @return LengthAwarePaginator|int ページのデータ、またはリダイレクト先の最終ページ。
     */
    public function __invoke(?string $keyword, ?PlanStatus $status, string $page): LengthAwarePaginator|int
    {
        $query = Plan::query();

        if ($keyword !== null) {
            $escaped = strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']);
            $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
        }

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $total = $query->count();
        $lastPage = $total === 0 ? 1 : intdiv($total - 1, self::PER_PAGE) + 1;
        $page = ltrim($page, '0');

        if (strnatcmp($page, (string) $lastPage) > 0) {
            return $lastPage;
        }

        $currentPage = (int) $page;
        $plans = $query
            ->withCount('inProgressStudents as users_count')
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [PlanStatus::Published->value])
            ->ordered()
            ->orderByDesc('id')
            ->forPage($currentPage, self::PER_PAGE)
            ->get();

        return (new LengthAwarePaginator($plans, $total, self::PER_PAGE, $currentPage, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]))->appends(['keyword' => $keyword, 'status' => $status?->value]);
    }
}
