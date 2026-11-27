<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingPack;

use App\Models\MeetingPack;

/**
 * 管理者向け面談パック更新リクエスト。
 */
class UpdateRequest extends SaveRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('plan');

        return $plan instanceof MeetingPack
            && ($this->user()?->can('update', $plan) ?? false);
    }
}
