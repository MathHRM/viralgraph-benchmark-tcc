<?php
declare(strict_types=1);
namespace App\Request;

use Hyperf\Validation\Request\FormRequest;

final class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['bail', 'required', 'string', 'max:32', 'regex:/\\S/u'],
            'apelido' => ['bail', 'required', 'string', 'max:100', 'regex:/\\S/u'],
            'nascimento' => 'bail|required|string|date_format:Y-m-d',
        ];
    }
}
