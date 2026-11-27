<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingPack;

use App\Models\MeetingPack;

/**
 * 管理者向け面談パック新規作成リクエスト。
 */
class StoreRequest extends SaveRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MeetingPack::class) ?? false;
    }
}
