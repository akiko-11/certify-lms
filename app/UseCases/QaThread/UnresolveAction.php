<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;

final class UnresolveAction
{
    public function __invoke(QaThread $thread): void
    {
        // すでに未解決の場合は変更しない
        if ($thread->status === QaThreadStatus::Unresolved) {
            return;
        }

        $thread->status = QaThreadStatus::Unresolved;
        $thread->resolved_at = null;

        $thread->save();
    }
}
