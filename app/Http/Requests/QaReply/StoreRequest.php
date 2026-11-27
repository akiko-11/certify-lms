<?php

declare(strict_types=1);

namespace App\Http\Requests\QaReply;

use App\Models\QaReply;
use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    /**
     * 回答投稿の認可を判定する。
     */
    public function authorize(): bool
    {
        $thread = $this->route('thread');

        return $thread instanceof QaThread
            && ($this->user()?->can('create', [QaReply::class, $thread]) ?? false);
    }

    /**
     * 回答本文のバリデーションルール。
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
     * バリデーションエラーメッセージ。
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
