<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlatformFeature;
use Illuminate\Support\Facades\Http;
use App\Exceptions\ExpoPushException;
use App\Channels\Messages\ExpoPushMessage;
use App\Contracts\ExpoPushServiceContract;
use Illuminate\Http\Client\ConnectionException;
use App\Exceptions\InvalidExpoPushTokenException;

class ExpoPushService implements ExpoPushServiceContract
{
    private const string BASE_URL = 'https://exp.host/--/api/v2/push';

    public function isEnabled(): bool
    {
        return resolve(FeatureFlagService::class)->enabled(PlatformFeature::ExpoPush);
    }

    public function send(string $token, ExpoPushMessage $message): string
    {
        $data = $this->post('/send', $message->payload($token))['data'] ?? null;
        if (! is_array($data)) {
            throw new ExpoPushException('Expo returned an invalid ticket.');
        }
        if (($data['details']['error'] ?? null) === 'DeviceNotRegistered') {
            throw new InvalidExpoPushTokenException('Expo registration is no longer valid.');
        }
        if (($data['status'] ?? null) !== 'ok' || ! is_string($data['id'] ?? null) || $data['id'] === '') {
            // Never include raw provider errors: they can echo a device token.
            throw new ExpoPushException('Expo did not accept the push message.');
        }

        return $data['id'];
    }

    public function receipt(string $receiptId): ?array
    {
        $receipt = $this->post('/getReceipts', ['ids' => [$receiptId]])['data'][$receiptId] ?? null;
        if (! is_array($receipt)) {
            return null;
        }

        return ['status' => $receipt['status'] ?? 'error',
            'details' => ['error' => $receipt['details']['error'] ?? '']];
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function post(string $path, array $payload): array
    {
        $request = Http::asJson()->acceptJson()->connectTimeout(5)->timeout(10);
        $accessToken = config('services.expo_push.access_token');
        if (is_string($accessToken) && $accessToken !== '') {
            $request = $request->withToken($accessToken);
        }
        try {
            $response = $request->post(self::BASE_URL.$path, $payload);
        } catch (ConnectionException) {
            throw new ExpoPushException('Expo push transport is unavailable.');
        }
        if (! $response->successful()) {
            throw new ExpoPushException('Expo push request failed with HTTP '.$response->status().'.');
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new ExpoPushException('Expo returned an invalid response.');
        }

        return $json;
    }
}
