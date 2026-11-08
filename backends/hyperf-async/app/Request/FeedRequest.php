<?php
declare(strict_types=1);
namespace App\Request;

final class FeedRequest extends ApiRequest
{
    protected bool $routeId = true;

    public function rules(): array
    {
        return [
            'id' => 'bail|required|uuid',
            'cursor' => 'sometimes|string',
        ];
    }
}
