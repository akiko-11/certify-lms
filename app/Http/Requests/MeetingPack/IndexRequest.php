<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * 管理者向け面談パック一覧の絞り込みリクエスト。
 */
class IndexRequest extends MeetingPackRequest
{
    public const ERROR_BAG = 'meeting-pack-index';

    public const OLD_KEYWORD = 'meeting-pack-index.old-keyword';

    protected $errorBag = self::ERROR_BAG;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', MeetingPack::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:100'],
            'status' => [
                'bail',
                'nullable',
                'string',
                Rule::in(array_column(MeetingPackStatus::cases(), 'value')),
            ],
            'page' => ['bail', 'nullable', 'integer', $this->requireIntegerType(...), 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'keyword' => '検索キーワード',
            'status' => 'ステータス',
            'page' => 'ページ番号',
        ];
    }

    protected function oldInputFields(): array
    {
        return ['keyword', 'status'];
    }

    protected function failedValidation(Validator $validator): void
    {
        $keyword = $this->input('keyword');

        if (! $this->expectsJson() && $validator->errors()->has('keyword') && is_string($keyword)) {
            $this->session()->flash(self::OLD_KEYWORD, $keyword);
        }

        parent::failedValidation($validator);
    }

    protected function flashErrorFields(Validator $validator): array
    {
        return ['page'];
    }

    protected function validationRedirectUrl(Validator $validator): string
    {
        $filters = array_diff_key($this->only($this->oldInputFields()), $validator->errors()->getMessages());

        return route('admin.meeting-packs.index', $filters);
    }
}
