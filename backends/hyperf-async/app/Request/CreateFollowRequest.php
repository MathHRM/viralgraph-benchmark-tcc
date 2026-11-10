<?php
declare(strict_types=1);
namespace App\Request;

use Hyperf\Validation\Request\FormRequest;

final class CreateFollowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seguidor_id' => 'bail|required|uuid',
            'seguido_id' => 'bail|required|uuid',
        ];
    }
}
