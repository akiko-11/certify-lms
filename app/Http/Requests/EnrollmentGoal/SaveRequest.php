<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentGoal;

use App\Exceptions\EnrollmentGoal\EnrollmentGoalValidationException;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

abstract class SaveRequest extends FormRequest
{
    private const FIELDS = ['title', 'description', 'target_date'];

    private const FORM_FIELDS = ['_token', '_method'];

    private const FORBIDDEN_MESSAGE = '変更できない項目が指定されています。';

    private const DATE_MESSAGE = '目標期日はYYYY-MM-DD形式の有効な日付で入力してください。';

    private static function formStateKey(Enrollment|EnrollmentGoal $subject): string
    {
        $form = $subject instanceof EnrollmentGoal ? 'edit' : 'create';

        return 'enrollment-goal-form.'.$form.'.'.$subject->getKey();
    }

    public static function restoreFormState(Enrollment|EnrollmentGoal $subject): array
    {
        $session = session();
        $state = $session->get(self::formStateKey($subject), []);
        $notice = $state['errors']['_input'][0] ?? null;

        if ($notice !== null) {
            $session->now('error', $notice);
        }

        return $state;
    }

    public function rules(): array
    {
        return [
            'title' => ['bail', 'required', 'string', 'max:100'],
            'description' => ['bail', 'nullable', 'string', 'max:1000'],
            'target_date' => [
                'bail',
                'nullable',
                'string',
                'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/',
                'date_format:Y-m-d',
                static function (string $attribute, string $value, Closure $fail): void {
                    if ($value < '1000-01-01' || $value > '9999-12-31') {
                        $fail(self::DATE_MESSAGE);
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => '目標を入力してください。',
            'title.string' => '目標は文字列で入力してください。',
            'title.max' => '目標は100文字以内で入力してください。',
            'description.string' => '詳細は文字列で入力してください。',
            'description.max' => '詳細は1000文字以内で入力してください。',
            'target_date.string' => self::DATE_MESSAGE,
            'target_date.regex' => self::DATE_MESSAGE,
            'target_date.date_format' => self::DATE_MESSAGE,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($validator->getData()), self::FIELDS, self::FORM_FIELDS) !== []) {
                $validator->errors()->add('_input', self::FORBIDDEN_MESSAGE);
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            throw new EnrollmentGoalValidationException($validator);
        }

        $subject = $this->route('goal') ?? $this->route('enrollment');
        $response = $subject instanceof EnrollmentGoal
            ? redirect()->route('enrollment-goals.edit', $subject)
            : redirect()->route('enrollments.show', $subject);

        $input = array_filter($this->only(self::FIELDS), static fn (mixed $value): bool => $value === null || is_string($value));
        $response->with(self::formStateKey($subject), [
            'input' => $input,
            'errors' => $validator->errors()->getMessages(),
        ]);

        throw new EnrollmentGoalValidationException($validator, $response);
    }
}
