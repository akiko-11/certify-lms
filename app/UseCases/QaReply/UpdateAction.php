<?php

declare(strict_types=1);

namespace App\UseCases\QaReply;

use App\Models\QaReply;

/**
 * 質問掲示板の回答本文を更新する。
 */
final class UpdateAction
{
    /**
     * @param array{body: string} $validated
     */
    public function __invoke(QaReply $reply, array $validated): void
    {
        $reply->body = $validated['body'];

        $reply->save();
    }
}
