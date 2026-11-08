<?php
declare(strict_types=1);
namespace App\Request;

use Hyperf\Validation\Request\FormRequest;

final class SearchPostRequest extends FormRequest
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
            'c' => ['bail', 'required', 'string', 'max:500', 'regex:/\\S/u'],
            'cursor' => 'sometimes|string',
        ];
    }
}
