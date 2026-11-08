<?php
declare(strict_types=1);
namespace App\Request;

use App\Validation\Input;
use App\Exception\ApiError;
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
            if (strtolower(trim(explode(';', $this->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                throw new ApiError(415, 'tipo_midia', 'Envie application/json.');
            }
            $raw = ltrim((string) $this->getBody(), " \t\r\n");
            if ($raw === '') {
                throw new ApiError(400, 'json_invalido', 'JSON malformado.');
            }
            // O Hyperf já validou e decodificou o JSON; o contrato exige um objeto.
            if ($raw[0] !== '{') {
                throw new ApiError();
            }
            $data = $this->getParsedBody();
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
