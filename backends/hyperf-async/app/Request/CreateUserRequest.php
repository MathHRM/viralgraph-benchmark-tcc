<?php
declare(strict_types=1);
namespace App\Request;

final class CreateUserRequest extends ApiRequest
{
    protected array $texts = ['nome' => true, 'apelido' => true];

    public function rules(): array
    {
        return [
            'nome' => ['bail', 'required', 'string', 'max:32'],
            'apelido' => ['bail', 'required', 'string', 'max:100'],
            'nascimento' => 'bail|required|string|date_format:Y-m-d',
        ];
    }
}
