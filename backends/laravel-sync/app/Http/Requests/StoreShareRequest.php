<?php

declare(strict_types=1);

namespace App\Http\Requests;

class StoreShareRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'usuario_id' => ['required', 'uuid'],
            'post_id' => ['required', 'uuid'],
        ];
    }

    protected function allowedInputKeys(): array
    {
        return ['usuario_id', 'post_id'];
    }
}
