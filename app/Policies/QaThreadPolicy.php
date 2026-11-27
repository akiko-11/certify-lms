<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * 質問掲示板のスレッドに対する認可ポリシー。
 *
 * - 受講生：公開中の資格のスレッドを閲覧・投稿できる。
 *   自分が投稿したスレッドのみ編集・解決状態の変更が可能。
 * - コーチ：担当する公開中の資格のスレッドのみ閲覧できる。
 * - 管理者：公開状態に関係なく全スレッドを閲覧・削除できる。
 *   投稿・編集・解決状態の変更はできない。
 *
 * 一覧の取得範囲はAction側で制御する。
 */
class QaThreadPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Student,
            UserRole::Coach,
            UserRole::Admin,
        ], true);
    }

    public function view(User $user, QaThread $thread): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        // 公開停止中の資格は受講生・コーチとも閲覧不可
        if ($thread->certification->status !== CertificationStatus::Published) {
            return false;
        }

        return match ($user->role) {
            UserRole::Student => true,
            UserRole::Coach => $thread->certification->coaches()
                ->where('users.id', $user->id)
                ->exists(),
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    public function update(User $user, QaThread $thread): bool
    {
        return $user->role === UserRole::Student
            && $thread->user_id === $user->id
            && $this->view($user, $thread);
    }

    public function resolve(User $user, QaThread $thread): bool
    {
        return $this->update($user, $thread);
    }

    public function unresolve(User $user, QaThread $thread): bool
    {
        return $this->update($user, $thread);
    }

    /**
     * スレッドを削除できるか判定する。
     *
     * 管理者は回答の有無に関係なく削除できる。
     * 受講生は自分の公開済資格の質問のみ削除できる。
     * ただし回答がある場合は409で拒否する。
     */
    public function delete(User $user, QaThread $thread): Response
    {
        // 管理者は無条件で削除可能
        if ($user->role === UserRole::Admin) {
            return Response::allow();
        }

        // 投稿者本人ではない、または公開停止中の場合は403
        if (
            $user->role !== UserRole::Student
            || $thread->user_id !== $user->id
            || $thread->certification->status !== CertificationStatus::Published
        ) {
            return Response::deny();
        }

        // 回答がある場合は409
        if ($thread->replies()->exists()) {
            return Response::denyWithStatus(
                409,
                '回答が付いているスレッドは削除できません。'
            );
        }

        return Response::allow();
    }
}
