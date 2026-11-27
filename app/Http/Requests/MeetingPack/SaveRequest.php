<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingPack;

use App\Models\MeetingPack;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\RedirectResponse;

abstract class SaveRequest extends MeetingPackRequest
{
    public static function formStateKey(?MeetingPack $plan = null): string
    {
        return 'meeting-pack-save.'.($plan?->getKey() ?? 'create');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'meeting_count' => ['bail', 'required', 'integer', $this->requireIntegerType(...), 'min:1', 'max:100'],
            'price' => ['bail', 'required', 'integer', $this->requireIntegerType(...), 'min:0', 'max:1000000'],
            'stripe_price_id' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['bail', 'nullable', 'integer', $this->requireIntegerType(...), 'min:0', 'max:9999'],
            'status' => ['missing'],
        ];
    }

    public function messages(): array
    {
        return ['status.missing' => 'ステータスは送信できません。'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'SKU 名',
            'description' => '説明',
            'meeting_count' => '面談回数',
            'price' => '価格',
            'stripe_price_id' => 'Stripe Price ID',
            'sort_order' => '並び順',
            'status' => 'ステータス',
        ];
    }

    protected function oldInputFields(): array
    {
        return ['name', 'description', 'meeting_count', 'price', 'stripe_price_id', 'sort_order'];
    }

    protected function flashErrorFields(Validator $validator): array
    {
        return ['status'];
    }

    protected function flashValidationState(RedirectResponse $response, Validator $validator): void
    {
        $input = array_filter(
            $this->only($this->oldInputFields()),
            fn (mixed $value, string $field): bool => $value === null
                || is_string($value)
                || (is_int($value) && in_array($field, ['meeting_count', 'price', 'sort_order'], true)),
            ARRAY_FILTER_USE_BOTH,
        );

        $response->with(self::formStateKey($this->route('plan')), [
            'input' => $input,
            'errors' => $validator->errors()->getMessages(),
        ]);
    }
}
