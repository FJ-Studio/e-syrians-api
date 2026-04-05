<?php

use App\Http\Middleware\SetAppLocalization;
use App\Services\ApiService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: '',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->append(SetAppLocalization::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return ApiService::error(401, __('api.unauthenticated'));
        });
        $exceptions->render(function (ValidationException $e, Request $request) {
            return ApiService::error(422, $e->errors());
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return ApiService::error(404, $e->getMessage());
        });
        $exceptions->render(function (HttpException $e, Request $request) {
            return ApiService::error($e->getStatusCode(), $e->getMessage());
        });
    })->create();
