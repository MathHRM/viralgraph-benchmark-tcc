<?php

declare(strict_types=1);

namespace App\Http\Requests;

class StoreShareRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'post_id' => ['required', 'uuid'],
            'usuario_id' => ['required', 'uuid'],
            'origem_usuario_id' => ['sometimes', 'required', 'uuid'],
        ];
    }

    protected function allowedInputKeys(): array
    {
        return ['usuario_id', 'origem_usuario_id'];
    }

    public function validationData(): array
    {
        return array_merge($this->all(), ['post_id' => $this->route('id')]);
    }
}
