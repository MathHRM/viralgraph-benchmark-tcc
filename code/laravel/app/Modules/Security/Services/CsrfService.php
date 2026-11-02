<?php

namespace App\Modules\Security\Services;

class CsrfService
{
    public function token(): string
    {
        return csrf_token();
    }
}
