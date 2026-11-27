<?php

declare(strict_types=1);

namespace App\Http\Requests\QaReply;

use App\Models\QaReply;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 質問掲示板の回答編集に関する認可と入力検証を行う。
 */
class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reply = $this->route('reply');

        return $reply instanceof QaReply
            && ($this->user()?->can('update', $reply) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => [
                'bail',
                'required',
                'string',
                'max:5000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => '回答を入力してください。',
            'body.string' => '回答は文字列で入力してください。',
            'body.max' => '回答は5000文字以内で入力してください。',
        ];
    }
}
