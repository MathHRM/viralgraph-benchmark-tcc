<?php
declare(strict_types=1);
namespace App\Request;

use Hyperf\Validation\Request\FormRequest;

final class FeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function validationData(): array
    {
        $data = $this->getQueryParams();
        $data['id'] = $this->route('id');
        return $data;
    }

    public function rules(): array
    {
        return [
            'id' => 'bail|required|uuid',
            'cursor' => 'sometimes|string',
        ];
    }
}
