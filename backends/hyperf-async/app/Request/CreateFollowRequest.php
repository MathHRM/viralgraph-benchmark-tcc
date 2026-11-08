<?php
declare(strict_types=1);
namespace App\Request;

final class CreateFollowRequest extends ApiRequest
{

    public function rules(): array
    {
        return [
            'seguidor_id' => 'bail|required|uuid',
            'seguido_id' => 'bail|required|uuid',
        ];
    }
}
