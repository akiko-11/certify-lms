<?php

declare(strict_types=1);

namespace App\Http\Requests\Plan;

use App\Exceptions\Plan\PlanValidationException;
use App\Models\Plan;
use App\UseCases\Plan\PlanBasicInformationHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

abstract class SaveRequest extends FormRequest
{
    private const SYSTEM_FIELDS = [
        'id',
        'plan_id',
        'status',
        'created_by_user_id',
        'updated_by_user_id',
        'created_at',
        'updated_at',
    ];

    public static function formStateKey(?Plan $plan = null): string
    {
        return 'plan-form.'.($plan?->getKey() ?? 'create');
    }

    public function rules(): array
    {
        return PlanBasicInformationHelper::rules() + array_fill_keys(self::SYSTEM_FIELDS, ['missing']);
    }

    public function attributes(): array
    {
        return [
            'name' => 'プラン名',
            'description' => '説明',
            'duration_days' => '受講期間',
            'default_meeting_quota' => '初期付与面談回数',
            'sort_order' => '並び順',
        ];
    }

    public function messages(): array
    {
        return ['name.regex' => 'プラン名を入力してください。']
            + array_fill_keys(array_map(fn (string $field): string => $field.'.missing', self::SYSTEM_FIELDS), '許可されていない項目が送信されています。');
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            throw new PlanValidationException($validator);
        }

        $plan = $this->route('plan');
        $response = $plan === null
            ? redirect()->route('admin.plans.create')
            : redirect()->route('admin.plans.edit', $plan);

        $input = array_filter(
            $this->only(array_keys($this->attributes())),
            static fn (mixed $value, string $field): bool => $value === null
                || is_string($value)
                || (is_int($value) && in_array($field, ['duration_days', 'default_meeting_quota', 'sort_order'], true)),
            ARRAY_FILTER_USE_BOTH,
        );

        $errors = $validator->errors();
        $response->with(self::formStateKey($plan), [
            'input' => $input,
            'errors' => $errors->getMessages(),
        ]);

        if ($errors->hasAny(self::SYSTEM_FIELDS)) {
            $response->with('error', '許可されていない項目が送信されています。');
        }

        throw new PlanValidationException($validator, $response);
    }
}
