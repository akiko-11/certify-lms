<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;

final class StoreAction
{
    /**
     * 回答を保存する。
     */
    public function __invoke(
        QaThread $thread,
        User $user,
        array $validated
    ): QaReply {
        $reply = new QaReply;

        $reply->qa_thread_id = $thread->id;
        $reply->user_id = $user->id;
        $reply->body = $validated['body'];

        $reply->save();

        return $reply;
    }
}
