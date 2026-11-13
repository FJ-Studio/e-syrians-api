<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\ConnectionException;

class DiditSandbox
{
    public function configured(): bool
    {
        return filled(config('identity-sandbox.api_key')) && filled(config('identity-sandbox.workflow_id'))
            && in_array(config('identity-sandbox.scenario'), ['approve', 'decline_document_expired'], true);
    }

    /** @return array{id: string, url: string} */
    public function create(string $attempt, string $locale): array
    {
        try {
            $response = $this->client()->post('session/', [
                'workflow_id' => $this->workflow(),
                'vendor_data' => $attempt,
                'language' => $locale,
                ...$this->sessionOptions(),
            ]);
        } catch (ConnectionException) {
            abort(502, 'identity_sandbox_provider_unreachable');
        }

        abort_unless($response->successful(), 502, 'identity_sandbox_provider_start_failed');

        $id = $response->json('session_id');
        $url = $response->json('url');
        $parts = is_string($url) ? parse_url($url) : false;

        abort_unless(
            is_string($id) && Str::isUuid($id) && is_string($url) && is_array($parts)
            && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? null) === 'verify.didit.me'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            // Some create responses omit session_kind. The sandbox workflow is
            // pinned to individual verification; reject any explicit other kind.
            && $this->matches($response->json('session_kind') ?? 'user', $response->json('workflow_id'), $response->json('vendor_data'), $attempt),
            502,
            'identity_sandbox_provider_session_invalid'
        );

        return ['id' => $id, 'url' => $url];
    }

    public function decision(string $session, string $attempt): string
    {
        try {
            $response = $this->client()->get("session/{$session}/decision/");
        } catch (ConnectionException) {
            abort(502, 'identity_sandbox_provider_unreachable');
        }

        abort_unless(
            $response->successful() && $response->json('session_id') === $session
            && $this->matches($response->json('session_kind'), $response->json('workflow_id'), $response->json('vendor_data'), $attempt)
            && $this->acceptsEnvironment($response->json('environment')),
            502,
            'identity_sandbox_provider_session_invalid'
        );

        $status = $response->json('status');

        return match (is_string($status) ? str_replace('_', ' ', strtolower($status)) : '') {
            'not started', 'in progress', 'awaiting user', 'resubmitted' => 'pending',
            'in review' => 'in_review',
            'approved' => 'approved',
            'declined' => 'declined',
            'expired', 'kyc expired', 'abandoned' => 'available',
            default => abort(502, 'identity_sandbox_provider_status_unknown'),
        };
    }

    protected function workflow(): mixed
    {
        return config('identity-sandbox.workflow_id');
    }

    /** @return array<string, mixed> */
    protected function sessionOptions(): array
    {
        // Didit rejects this field when the key belongs to a live application.
        return ['sandbox_scenario' => config('identity-sandbox.scenario')];
    }

    protected function matches(mixed $kind, mixed $workflow, mixed $vendorData, string $attempt): bool
    {
        return $kind === 'user' && $workflow === $this->workflow() && $vendorData === $attempt;
    }

    protected function key(): mixed
    {
        return config('identity-sandbox.api_key');
    }

    protected function acceptsEnvironment(mixed $environment): bool
    {
        return $environment === null || $environment === 'sandbox';
    }

    protected function client(): PendingRequest
    {
        abort_unless($this->configured(), 503, 'identity_sandbox_not_configured');

        return Http::baseUrl('https://verification.didit.me/v3/')
            ->acceptJson()->withHeaders(['x-api-key' => $this->key()])
            ->withoutRedirecting()->connectTimeout(3)->timeout(7);
    }
}
