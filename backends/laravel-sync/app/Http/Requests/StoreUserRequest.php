<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreUserRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:32', 'regex:/\S/u'],
            'apelido' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'nascimento' => ['required', 'date_format:Y-m-d'],
        ];
    }

    protected function allowedInputKeys(): array
    {
        return ['nome', 'apelido', 'nascimento'];
    }

    protected function normalizedTextFields(): array
    {
        return ['nome', 'apelido'];
    }

    protected function trimmedTextFields(): array
    {
        return ['nome', 'apelido'];
    }

    protected function afterValidation(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $referenceDate = config('benchmark.reference_date');

        if (! is_string($referenceDate) || $referenceDate === '') {
            throw new HttpResponseException(ApiResponse::unavailable(
                'A data de referência do benchmark não está configurada.'
            ));
        }

        try {
            $reference = CarbonImmutable::createFromFormat('!Y-m-d', $referenceDate);
        } catch (\Throwable) {
            $reference = false;
        }

        if ($reference === false || $reference->format('Y-m-d') !== $referenceDate) {
            throw new HttpResponseException(ApiResponse::unavailable(
                'A data de referência do benchmark é inválida.'
            ));
        }

        $birthDate = CarbonImmutable::createFromFormat('!Y-m-d', $this->string('nascimento')->toString());

        if ($birthDate->greaterThan($reference->subYears(18))) {
            $validator->errors()->add('nascimento', 'O usuário deve ter pelo menos 18 anos na data de referência.');
        }
    }
}
