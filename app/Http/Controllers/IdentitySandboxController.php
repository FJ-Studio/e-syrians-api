<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Contracts\IdentitySandboxServiceContract;

class IdentitySandboxController extends Controller
{
    public function __construct(private readonly IdentitySandboxServiceContract $sandbox)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return $this->summary($this->user($request));
    }

    public function history(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        return $this->respond($this->sandbox->history($user, (int) ($validated['page'] ?? 1)));
    }

    public function claim(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->sandbox->claim($user);

        return $this->summary($user);
    }

    public function giftCode(Request $request): JsonResponse
    {
        return $this->respond($this->sandbox->giftCode($this->user($request)));
    }

    public function gift(Request $request, string $credit): JsonResponse
    {
        $user = $this->user($request);
        $code = $request->validate(['code' => ['required', 'string', 'alpha_num:ascii', 'size:32']])['code'];
        $this->sandbox->gift($user, $credit, $code);

        return $this->summary($user);
    }

    public function start(Request $request, string $credit): JsonResponse
    {
        $user = $this->user($request);
        $locale = $request->validate(['locale' => ['sometimes', 'in:en,ar']])['locale'] ?? 'en';

        return $this->respond(['url' => $this->sandbox->start($user, $credit, $locale)]);
    }

    public function refresh(Request $request, string $credit): JsonResponse
    {
        $user = $this->user($request);
        $this->sandbox->refresh($user, $credit);

        return $this->summary($user);
    }

    protected function summary(User $user): JsonResponse
    {
        return $this->respond($this->sandbox->summary($user));
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $this->sandbox->allowed($user), 404);

        return $user;
    }

    /** @param array<string, mixed> $data */
    protected function respond(array $data): JsonResponse
    {
        return ApiService::success($data)->header('Cache-Control', 'private, no-store');
    }
}
