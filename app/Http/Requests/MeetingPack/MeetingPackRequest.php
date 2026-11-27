<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingPack;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

abstract class MeetingPackRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        $errors = $validator->errors();
        $response = redirect()->to($this->validationRedirectUrl($validator));
        $this->flashValidationState($response, $validator);

        $messages = array_filter(array_map($errors->first(...), $this->flashErrorFields($validator)));

        if ($messages !== []) {
            $response->with('error', implode(' ', $messages));
        }

        throw new ValidationException($validator, $response);
    }

    protected function flashValidationState(RedirectResponse $response, Validator $validator): void
    {
        $response
            ->withInput(array_filter($this->only($this->oldInputFields()), fn (mixed $value): bool => $value === null || is_scalar($value)))
            ->withErrors($validator->errors(), $this->errorBag);
    }

    /** @return list<string> */
    abstract protected function oldInputFields(): array;

    /** @return list<string> */
    abstract protected function flashErrorFields(Validator $validator): array;

    protected function validationRedirectUrl(Validator $validator): string
    {
        return $this->getRedirectUrl();
    }

    protected function requireIntegerType(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) && ! is_string($value)) {
            $fail('validation.integer')->translate();
        }
    }
}
