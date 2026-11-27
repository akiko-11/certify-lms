<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 質問スレッドの編集に関する認可と入力検証を行う。
 *
 * 投稿者本人のみ更新可能とし、
 * 資格IDの変更要求は拒否する。
 */
class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $thread = $this->route('thread');

        return $thread instanceof QaThread
            && ($this->user()?->can('update', $thread) ?? false);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'certification_id' => [
                'missing',
            ],
            'title' => [
                'bail',
                'required',
                'string',
                'max:200',
            ],
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
            'certification_id.missing' => '資格は変更できません。',

            'title.required' => 'タイトルを入力してください。',
            'title.string' => 'タイトルは文字列で入力してください。',
            'title.max' => 'タイトルは200文字以内で入力してください。',

            'body.required' => '本文を入力してください。',
            'body.string' => '本文は文字列で入力してください。',
            'body.max' => '本文は5000文字以内で入力してください。',
        ];
    }
}
