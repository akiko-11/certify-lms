<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;

/**
 * 質問掲示板の回答に対する認可を管理する。
 *
 * 受講生は公開済資格に回答できる。
 * コーチは担当する公開済資格にのみ回答できる。
 * 回答の編集・削除は投稿者本人に限定する。
 * 管理者は任意の回答を削除できるが、投稿・編集はできない。
 */
class QaReplyPolicy
{
    /**
     * 回答を投稿できるか判定する。
     */
    public function create(User $user, QaThread $thread): bool
    {
        // 公開停止中の資格には回答できない
        if ($thread->certification->status !== CertificationStatus::Published) {
            return false;
        }

        return match ($user->role) {
            // 受講生は公開済資格のスレッドに回答可能
            UserRole::Student => true,

            // コーチは担当資格のスレッドにのみ回答可能
            UserRole::Coach => $thread->certification
                ->coaches()
                ->where('users.id', $user->id)
                ->exists(),

            // 管理者は回答不可
            default => false,
        };
    }

    /**
     * 回答を編集できるか判定する。
     */
    public function update(User $user, QaReply $reply): bool
    {
        return $reply->user_id === $user->id
            && $this->create($user, $reply->thread);
    }

    /**
     * 回答を削除できるか判定する。
     */
    public function delete(User $user, QaReply $reply): bool
    {
        // 管理者は任意の回答を削除可能
        if ($user->role === UserRole::Admin) {
            return true;
        }

        // 受講生・コーチは自分の回答のみ削除可能
        return $this->update($user, $reply);
    }
}
