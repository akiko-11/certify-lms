<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentGoal;

use App\Models\EnrollmentGoal;

final class StoreRequest extends SaveRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [EnrollmentGoal::class, $this->route('enrollment')]);
    }
}
