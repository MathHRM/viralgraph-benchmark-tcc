<?php
declare(strict_types=1);
namespace App\Request;

use Hyperf\Validation\Request\FormRequest;

final class CreatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'usuario_id' => 'bail|required|uuid',
            'conteudo' => ['bail', 'required', 'string', 'max:500', 'regex:/\\S/u'],
        ];
    }
}
