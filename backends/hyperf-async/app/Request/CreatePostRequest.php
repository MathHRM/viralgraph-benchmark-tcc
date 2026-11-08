<?php
declare(strict_types=1);
namespace App\Request;

final class CreatePostRequest extends ApiRequest
{
    protected array $texts = ['conteudo' => false];

    public function rules(): array
    {
        return [
            'usuario_id' => 'bail|required|uuid',
            'conteudo' => ['bail', 'required', 'string', 'max:500', 'regex:/\\S/u'],
        ];
    }
}
