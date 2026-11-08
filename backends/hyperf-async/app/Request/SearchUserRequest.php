<?php
declare(strict_types=1);
namespace App\Request;

use Hyperf\Validation\Request\FormRequest;

final class SearchUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function validationData(): array
    {
        return $this->getQueryParams();
    }

    public function rules(): array
    {
        return [
            's' => ['bail', 'required', 'string', 'max:100', 'regex:/\\S/u'],
            'cursor' => 'sometimes|string',
        ];
    }
}
