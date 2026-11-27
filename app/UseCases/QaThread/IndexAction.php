<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * 質問掲示板の一覧を取得する。
 *
 * ロール別の閲覧範囲、検索条件、ページネーションを管理する。
 */
final class IndexAction
{
    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function __invoke(User $user, array $filters): array
    {
        $threadQuery = QaThread::query()
            ->with(['user', 'certification'])
            ->withCount('replies');

        $certificationQuery = Certification::query();

        // ロール別の表示対象を制限
        switch ($user->role) {
            case UserRole::Student:
                $threadQuery->whereHas(
                    'certification',
                    fn (Builder $query) => $query->published()
                );

                $certificationQuery->published();
                break;

            case UserRole::Coach:
                $threadQuery->whereHas(
                    'certification',
                    fn (Builder $query) => $query
                        ->published()
                        ->assignedTo($user)
                );

                $certificationQuery
                    ->published()
                    ->assignedTo($user);
                break;

            case UserRole::Admin:
                // 管理者は公開状態に関係なく全資格を閲覧可能
                break;

            default:
                $threadQuery->whereRaw('1 = 0');
                $certificationQuery->whereRaw('1 = 0');
                break;
        }

        // 資格による絞り込み
        if (! empty($filters['certification_id'])) {
            $threadQuery->where(
                'certification_id',
                $filters['certification_id']
            );
        }

        // 解決状態による絞り込み
        if (! empty($filters['status'])) {
            $threadQuery->where('status', $filters['status']);
        }

        // キーワード検索（タイトル・質問本文・回答本文のOR部分一致）
        $keyword = $filters['keyword'] ?? null;

        if (is_string($keyword) && trim($keyword) !== '') {
            $likeKeyword = '%'.trim($keyword).'%';

            $threadQuery->where(function (Builder $query) use ($likeKeyword) {
                $query->where('title', 'like', $likeKeyword)
                    ->orWhere('body', 'like', $likeKeyword)
                    ->orWhereHas(
                        'replies',
                        fn (Builder $replyQuery) => $replyQuery
                            ->where('body', 'like', $likeKeyword)
                    );
            });
        }

        return [
            'threads' => $threadQuery
                ->latest('created_at')
                ->latest('id')
                ->paginate(20)
                ->withQueryString(),

            'certifications' => $certificationQuery
                ->orderBy('name')
                ->get(),
        ];
    }
}
