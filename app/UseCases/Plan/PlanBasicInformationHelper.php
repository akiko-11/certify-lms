<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Models\Plan;
use Closure;
use Illuminate\Support\Facades\Validator;

final class PlanBasicInformationHelper
{
    public static function rules(): array
    {
        $integerType = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value) && ! is_string($value)) {
                $fail('validation.integer')->translate();
            }
        };

        return [
            'name' => ['bail', 'required', 'string', 'max:100', 'regex:/[^\s\x{FEFF}\x{200B}]/u'],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_days' => ['bail', 'required', $integerType, 'integer', 'between:1,3650'],
            'default_meeting_quota' => ['bail', 'required', $integerType, 'integer', 'between:0,1000'],
            'sort_order' => ['bail', 'nullable', $integerType, 'integer', 'between:0,4294967295'],
        ];
    }

    public static function isValid(Plan $plan): bool
    {
        $rules = self::rules();

        return Validator::make($plan->only(array_keys($rules)), $rules)->passes();
    }

    public static function attributesForSave(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'duration_days' => (int) $validated['duration_days'],
            'default_meeting_quota' => (int) $validated['default_meeting_quota'],
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
    }
}
