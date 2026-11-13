<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\CursorCodec;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class SearchUsersRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            's' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'cursor' => ['sometimes', 'string', 'min:1'],
        ];
    }

    protected function allowedInputKeys(): array
    {
        return ['s', 'cursor'];
    }

    protected function normalizedTextFields(): array
    {
        return ['s'];
    }

    protected function afterValidation(Validator $validator): void
    {
        if (! $validator->errors()->has('cursor') && $this->has('cursor')) {
            try {
                CursorCodec::decode($this->string('cursor')->toString());
            } catch (ValidationException $exception) {
                foreach ($exception->errors()['cursor'] ?? [] as $message) {
                    $validator->errors()->add('cursor', $message);
                }
            }
        }
    }
}
