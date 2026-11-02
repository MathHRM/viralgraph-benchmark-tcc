<?php

use App\Modules\Security\Http\Middleware\EnsureAuthenticated;
use App\Modules\Shared\Exceptions\SocialDomainException;
use App\Modules\Shared\Http\Middleware\RecordHttpMetrics;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RecordHttpMetrics::class);
        $middleware->alias(['api.auth' => EnsureAuthenticated::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => true,
        );
        $exceptions->render(function (ValidationException $exception, Request $request) {
            return response()->json(['error' => [
                'code' => 'validation_failed',
                'message' => 'The submitted data is invalid.',
                'details' => $exception->errors(),
            ]], 422);
        });
        $exceptions->render(function (SocialDomainException $exception, Request $request) {
            $status = match ($exception->errorCode) {
                'username_taken' => 409,
                'user_not_found', 'resource_not_found', 'share_not_found' => 404,
                default => 422,
            };
            $error = [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ];
            if ($exception->details !== []) {
                $error['details'] = $exception->details;
            }

            return response()->json(['error' => $error], $status);
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            $status = $exception->getStatusCode();
            $code = match ($status) {
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                419 => 'csrf_mismatch',
                default => 'request_failed',
            };

            return response()->json(['error' => [
                'code' => $code,
                'message' => $status === 419 ? 'The session token is invalid or expired.' : ($exception->getMessage() ?: 'The request could not be completed.'),
            ]], $status);
        });
    })->create();
