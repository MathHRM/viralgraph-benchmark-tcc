<?php

declare(strict_types=1);

namespace App\Http\Requests;

class StoreFollowRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'seguidor_id' => ['required', 'uuid'],
            'seguido_id' => ['required', 'uuid'],
        ];
    }

    protected function allowedInputKeys(): array
    {
        return ['seguidor_id', 'seguido_id'];
    }
}
