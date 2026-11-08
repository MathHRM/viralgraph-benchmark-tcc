<?php
declare(strict_types=1);
namespace App\Request;

final class CreateShareRequest extends ApiRequest
{

    public function rules(): array
    {
        return [
            'usuario_id' => 'bail|required|uuid',
            'post_id' => 'bail|required|uuid',
        ];
    }
}
