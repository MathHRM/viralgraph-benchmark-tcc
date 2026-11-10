<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\ApiResponse;
use App\Support\TextNormalizer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return list<string> */
    abstract protected function allowedInputKeys(): array;

    /** @return list<string> */
    protected function normalizedTextFields(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function trimmedTextFields(): array
    {
        return [];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        foreach ($this->normalizedTextFields() as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $value = TextNormalizer::normalize($value);

                if (in_array($field, $this->trimmedTextFields(), true)) {
                    $value = trim($value);
                }

                $values[$field] = $value;
            }
        }

        if ($values !== []) {
            $this->merge($values);
        }
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), $this->allowedInputKeys()) as $unknownKey) {
                $validator->errors()->add($unknownKey, 'Este campo não é permitido.');
            }

            $this->afterValidation($validator);
        });
    }

    protected function afterValidation(Validator $validator): void
    {
        // Subclasses may add cross-field or domain validation.
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(ApiResponse::validationError($validator->errors()->toArray()));
    }
}
