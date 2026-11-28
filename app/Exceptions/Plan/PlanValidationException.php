<?php

declare(strict_types=1);

namespace App\Exceptions\Plan;

use Illuminate\Validation\ValidationException;

final class PlanValidationException extends ValidationException
{
    protected static function summarize($validator): string
    {
        $messages = $validator->errors()->all();
        $first = array_shift($messages) ?? '入力内容を確認してください。';

        return $messages === [] ? $first : $first.'（ほか'.count($messages).'件のエラー）';
    }
}
