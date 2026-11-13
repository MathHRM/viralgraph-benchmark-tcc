<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\CursorCodec;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class SearchPostsRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'c' => ['required', 'string', 'max:500', 'regex:/\S/u'],
            'cursor' => ['sometimes', 'string', 'min:1'],
        ];
    }

    protected function allowedInputKeys(): array
    {
        return ['c', 'cursor'];
    }

    protected function normalizedTextFields(): array
    {
        return ['c'];
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
