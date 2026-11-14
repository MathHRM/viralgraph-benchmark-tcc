<?php
declare(strict_types=1);
namespace App\Request;

use Hyperf\Validation\Request\FormRequest;

final class CreateShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function validationData(): array
    {
        $data = $this->all();
        $data['post_id'] = $this->route('id');
        return $data;
    }

    public function rules(): array
    {
        return [
            'post_id' => 'bail|required|uuid',
            'usuario_id' => 'bail|required|uuid',
            'origem_usuario_id' => 'bail|sometimes|required|uuid',
        ];
    }
}
