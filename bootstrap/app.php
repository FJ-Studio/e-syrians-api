<?php

use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Application;
use App\Http\Middleware\Recaptcha;
use App\Http\Middleware\SetAppLocalization;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(SetAppLocalization::class);

        // Spatie Permission v6 no longer auto-registers these aliases in
        // Laravel 11's bootstrap structure — register them explicitly so
        // `role:admin` / `permission:…` can be used in route middleware.
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'recaptcha' => Recaptcha::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
