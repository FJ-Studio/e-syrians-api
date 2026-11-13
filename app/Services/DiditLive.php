<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Illuminate\Http\Client\ConnectionException;

class DiditLive extends DiditSandbox
{
    public function configured(): bool
    {
        return filled($this->key()) && filled($this->workflow())
            && $this->key() !== config('identity-sandbox.api_key')
            && $this->workflow() !== config('identity-sandbox.workflow_id');
    }

    /** @return array{id: string, url: string} */
    public function create(string $attempt, string $locale): array
    {
        abort_unless(app()->environment('production', 'testing'), 503, 'identity_live_unavailable');
        $session = parent::create($attempt, $locale);
        // The create response does not declare the provider environment.
        // Confirm it using the authenticated decision endpoint before opening it.
        $this->decision($session['id'], $attempt);

        return $session;
    }

    public function erase(string $session): void
    {
        try {
            $response = $this->client()->delete("session/{$session}/delete/", [
                'deletion_instruction' => 'privacy_erasure',
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Didit privacy erasure did not complete.');
        }
        if (! $response->successful() && ! $response->notFound()) {
            throw new RuntimeException('Didit privacy erasure did not complete.');
        }
    }

    protected function key(): mixed
    {
        return config('identity-verification.didit.key');
    }

    protected function workflow(): mixed
    {
        return config('identity-verification.didit.workflow');
    }

    protected function sessionOptions(): array
    {
        return [];
    }

    protected function acceptsEnvironment(mixed $environment): bool
    {
        return $environment === 'live';
    }
}
