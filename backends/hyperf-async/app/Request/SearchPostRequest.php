<?php
declare(strict_types=1);
namespace App\Request;

final class SearchPostRequest extends ApiRequest
{
    protected array $texts = ['c' => false];

    public function rules(): array
    {
        return [
            'c' => ['bail', 'required', 'string', 'max:500', 'regex:/\\S/u'],
            'cursor' => 'sometimes|string',
        ];
    }
}
