<?php
declare(strict_types=1);
namespace App\Validation;

use App\Exception\ApiError;
use Hyperf\HttpServer\Contract\RequestInterface;

final class RequestInput
{
    public static function body(RequestInterface $request, array $fields): array
    {
        self::query($request, []);
        return Input::body((string) $request->getBody(), $request->getHeaderLine('Content-Type'), $fields);
    }

    public static function query(RequestInterface $request, array $allowed): array
    {
        $query = $request->getQueryParams();
        if (array_diff(array_keys($query), $allowed) || (isset($query['cursor']) && !is_string($query['cursor']))) {
            throw new ApiError();
        }
        return $query;
    }
}
