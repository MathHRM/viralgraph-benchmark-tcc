<?php

declare(strict_types=1);

namespace App\Http\Requests;

class FeedRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'cursor' => ['sometimes', 'string'],
        ];
    }

    protected function allowedInputKeys(): array
    {
        return ['cursor', 'id'];
    }

    public function validationData(): array
    {
        return array_merge($this->all(), ['id' => $this->route('id')]);
    }
}
