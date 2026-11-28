<?php

declare(strict_types=1);

namespace App\Http\Requests\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanValidationException;
use App\Models\Plan;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Plan::class);
    }

    public function validationData(): array
    {
        return $this->query();
    }

    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
            'status' => ['bail', 'nullable', 'string', Rule::in(array_column(PlanStatus::cases(), 'value'))],
            // クエリ文字列のまま検証し、PHPの整数上限を超えるページ番号も扱う。
            'page' => ['bail', 'sometimes', 'required', 'regex:/\A[0-9]+\z/', 'not_regex:/\A0+\z/'],
        ];
    }

    public function attributes(): array
    {
        return [
            'keyword' => '検索キーワード',
            'status' => 'ステータス',
            'page' => 'ページ番号',
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => '有効なステータスを選択してください。',
            'page.regex' => 'ページ番号は整数で指定してください。',
            'page.not_regex' => 'ページ番号は1以上で指定してください。',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            throw new PlanValidationException($validator);
        }

        $errors = $validator->errors();
        $messages = array_map($errors->first(...), $errors->keys());
        $response = redirect()->route('admin.plans.index')->with('error', implode(' ', $messages));

        throw new PlanValidationException($validator, $response);
    }
}
