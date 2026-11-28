<?php

declare(strict_types=1);

namespace App\Exceptions\EnrollmentGoal;

use Illuminate\Validation\ValidationException;

final class EnrollmentGoalValidationException extends ValidationException
{
    protected static function summarize($validator): string
    {
        $messages = $validator->errors()->all();
        $first = array_shift($messages) ?? '入力内容を確認してください。';

        return $messages === [] ? $first : $first.'（ほか'.count($messages).'件のエラー）';
    }
}
