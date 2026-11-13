<?php

declare(strict_types=1);

namespace App\Http\Requests;

class ShowUserRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['id' => ['required', 'uuid']];
    }

    protected function allowedInputKeys(): array
    {
        return [];
    }

    public function validationData(): array
    {
        return array_merge($this->all(), ['id' => $this->route('id')]);
    }
}
