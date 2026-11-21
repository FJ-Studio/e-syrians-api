<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ApiService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\Contracts\HasAbilities;

class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $this->requirePersistentSession($user->currentAccessToken());
        $now = now();
        $expiration = config('sanctum.expiration');
        $locale = $request->getPreferredLanguage(array_keys(config('e-syrians.locales')));

        $sessions = $user->tokens()
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->when(is_numeric($expiration) && (int) $expiration > 0, fn ($query) => $query->where('created_at', '>', $now->copy()->subMinutes((int) $expiration)))
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->orderByDesc('id')
            ->get();

        // Optional push registrations can supply a device label, but they don't determine whether a session is active.
        $devices = $user->devices()->whereIn('personal_access_token_id', $sessions->modelKeys())
            ->orderBy('last_seen_at')->orderBy('id')
            ->get(['personal_access_token_id', 'model', 'platform'])->keyBy('personal_access_token_id');

        return ApiService::success(['sessions' => $sessions->map(function (PersonalAccessToken $session) use ($current, $devices, $now, $locale): array {
            $isCurrent = $session->getKey() === $current->getKey();
            $device = $devices->get($session->getKey());
            $label = $device?->model ?: match ($device?->platform) {
                'ios' => 'iOS',
                'android' => 'Android',
                default => __($isCurrent ? 'api.session_this_device' : 'api.session_unknown_device', [], $locale),
            };

            return [
                'id' => (string) $session->getKey(),
                'device' => $label,
                'last_active_at' => ($session->last_used_at ?? $session->created_at ?? $now)->toIso8601String(),
                'is_current' => $isCurrent,
            ];
        })])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, string $session): JsonResponse
    {
        $user = $request->user();
        $current = $this->requirePersistentSession($user->currentAccessToken());
        $removed = $user->tokens()->whereKeyNot($current->getKey())->whereKey($session)->delete();
        abort_if($removed === 0, 404);

        return ApiService::success([]);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $this->requirePersistentSession($user->currentAccessToken());
        $user->tokens()->whereKeyNot($current->getKey())->delete();

        return ApiService::success([]);
    }

    private function requirePersistentSession(?HasAbilities $token): PersonalAccessToken
    {
        // A cookie-only login has no current API token to preserve.
        abort_unless($token instanceof PersonalAccessToken, 403);

        return $token;
    }
}
