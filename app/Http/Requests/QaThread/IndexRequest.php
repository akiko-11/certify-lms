<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 質問掲示板の一覧検索条件を検証する。
 */
class IndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', QaThread::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'certification_id' => ['nullable', 'ulid'],
            'status' => [
                'nullable',
                Rule::in(array_column(QaThreadStatus::cases(), 'value')),
            ],
            'keyword' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * バリデーションエラーメッセージを定義する。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'certification_id.ulid' => '資格の指定が正しくありません。',

            'status.in' => '解決状態の指定が正しくありません。',

            'keyword.string' => 'キーワードは文字列で入力してください。',
            'keyword.max' => 'キーワードは100文字以内で入力してください。',

            'page.integer' => 'ページ番号は整数で指定してください。',
            'page.min' => 'ページ番号は1以上で指定してください。',
        ];
    }
}
