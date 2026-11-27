<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;

final class ResolveAction
{
    public function __invoke(QaThread $thread): void
    {
        // すでに解決済みの場合は変更しない
        if ($thread->status === QaThreadStatus::Resolved) {
            return;
        }

        $thread->status = QaThreadStatus::Resolved;
        $thread->resolved_at = now();

        $thread->save();
    }
}
