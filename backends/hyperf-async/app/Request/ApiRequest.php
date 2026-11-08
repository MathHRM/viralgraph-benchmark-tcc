<?php
declare(strict_types=1);
namespace App\Request;

use App\Validation\Input;
use Hyperf\Validation\Request\FormRequest;

abstract class ApiRequest extends FormRequest
{
    protected array $texts = [];
    protected bool $routeId = false;

    public function authorize(): bool
    {
        return true;
    }

    protected function validationData(): array
    {
        if ($this->getMethod() === 'POST') {
            $data = Input::body((string) $this->getBody(), $this->getHeaderLine('Content-Type'));
        } else {
            $data = $this->getQueryParams();
            if ($this->routeId) {
                $data['id'] = $this->route('id');
            }
        }
        foreach ($this->texts as $field => $trim) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = Input::normalize($data[$field], $trim);
            }
        }
        foreach ($data as $field => $value) {
            if (($field === 'id' || str_ends_with($field, '_id')) && is_string($value)) {
                $data[$field] = strtolower($value);
            }
        }
        return $data;
    }
}
