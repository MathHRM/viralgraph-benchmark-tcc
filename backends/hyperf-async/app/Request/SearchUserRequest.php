<?php
declare(strict_types=1);
namespace App\Request;

final class SearchUserRequest extends ApiRequest
{
    protected array $texts = ['s' => false];

    public function rules(): array
    {
        return [
            's' => ['bail', 'required', 'string', 'max:100', 'regex:/\\S/u'],
            'cursor' => 'sometimes|string',
        ];
    }
}
