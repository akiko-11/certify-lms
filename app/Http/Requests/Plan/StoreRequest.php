<?php

declare(strict_types=1);

namespace App\Http\Requests\Plan;

use App\Models\Plan;

class StoreRequest extends SaveRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Plan::class);
    }
}
