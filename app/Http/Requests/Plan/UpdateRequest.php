<?php

declare(strict_types=1);

namespace App\Http\Requests\Plan;

class UpdateRequest extends SaveRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('plan'));
    }
}
