<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;

/**
 * 管理者向け面談パック詳細の取得ユースケース。
 */
final class ShowAction
{
    public function __invoke(MeetingPack $plan): MeetingPack
    {
        return $plan->loadMissing([
            'createdBy',
            'updatedBy',
        ]);
    }
}
