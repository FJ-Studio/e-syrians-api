<?php

namespace Tests;

use App\Http\Middleware\Recaptcha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Indicates whether the default seeder should run before each test.
     *
     * @var bool
     */
    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Recaptcha is enforced at the route level in production. Feature
        // tests aren't exercising the real Google siteverify call — the
        // middleware has a dedicated unit test (RecaptchaMiddlewareTest) and
        // a wiring test (RecaptchaRouteProtectionTest) that re-enables it
        // via `$this->app->forgetInstance(Recaptcha::class)`.
        $this->withoutMiddleware(Recaptcha::class);
    }
}
