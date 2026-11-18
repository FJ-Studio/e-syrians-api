<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;
use Illuminate\Support\Facades\Process;
use Illuminate\Contracts\Process\ProcessResult;
use Symfony\Component\Process\ExecutableFinder;

/** Apple's official verifier runs without access to Laravel's credentials. */
class AppleSignedTransactionVerifier
{
    public function configured(): bool
    {
        $roots = config('identity-verification.store.ios.root_certificates');
        $id = config('identity-verification.store.ios.app_id');
        if (! is_array($roots) || $roots === [] || ! is_numeric($id) || (int) $id <= 0
            || $this->node() === null
            || ! is_readable(base_path('node_modules/@apple/app-store-server-library/dist/index.js'))) {
            return false;
        }

        foreach ($roots as $path) {
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                return false;
            }
            $certificate = @openssl_x509_parse((string) file_get_contents($path));
            if (! is_array($certificate) || ! str_contains($certificate['extensions']['basicConstraints'] ?? '', 'CA:TRUE')
                || ($certificate['validFrom_time_t'] ?? PHP_INT_MAX) > time() + 60
                || ($certificate['validTo_time_t'] ?? 0) < time() - 60) {
                return false;
            }
        }

        try {
            $result = $this->run(['check' => true, 'environment' => 'production']);

            return $result->successful() && json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR) === ['ready' => true];
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function verify(string $transaction, string $environment): array
    {
        abort_unless($this->configured() && in_array($environment, ['production', 'sandbox'], true), 503, 'identity_store_unavailable');
        abort_unless(strlen($transaction) <= 65536, 422, 'identity_store_invalid_purchase');

        try {
            $result = $this->run(['environment' => $environment, 'transaction' => $transaction]);
            $data = $result->successful() ? json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            // Never expose process input, output, a purchase JWS or credentials.
            abort(503, 'identity_store_unavailable');
        }

        abort_if($result->exitCode() === 10, 422, 'identity_store_invalid_purchase');
        abort_unless(is_array($data), 503, 'identity_store_unavailable');

        return $data;
    }

    /** @param array<string, mixed> $input */
    private function run(array $input): ProcessResult
    {
        // Symfony otherwise inherits the parent environment. Do not expose
        // Stripe/Didit/Apple private keys (or NODE_OPTIONS hooks) to Node.
        $names = array_unique([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]);
        $environment = array_fill_keys($names, false);
        $environment['PATH'] = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';
        $environment['NODE_OPTIONS'] = false;
        $environment['NODE_PATH'] = false;

        return Process::path(base_path())->timeout(20)->env($environment)
            ->input(json_encode([
                'roots' => config('identity-verification.store.ios.root_certificates'),
                'bundle' => config('identity-verification.store.ios.bundle'),
                'appId' => (int) config('identity-verification.store.ios.app_id'),
                ...$input,
            ], JSON_THROW_ON_ERROR))
            ->run([$this->node(), base_path('scripts/apple/verify-transaction.mjs')]);
    }

    private function node(): ?string
    {
        $binary = config('identity-verification.store.ios.node_binary');
        if (is_string($binary) && str_starts_with($binary, '/')) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }

        return is_string($binary) && $binary !== '' ? (new ExecutableFinder())->find($binary) : null;
    }
}
