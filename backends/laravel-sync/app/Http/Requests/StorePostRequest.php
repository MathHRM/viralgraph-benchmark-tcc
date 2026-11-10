<?php

declare(strict_types=1);

namespace App\Http\Requests;

class StorePostRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'usuario_id' => ['required', 'uuid'],
            'conteudo' => ['required', 'string', 'max:500', 'regex:/\S/u'],
        ];
    }

    protected function allowedInputKeys(): array
    {
        return ['usuario_id', 'conteudo'];
    }

    protected function normalizedTextFields(): array
    {
        return ['conteudo'];
    }
}
