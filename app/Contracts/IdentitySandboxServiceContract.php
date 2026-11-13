<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

interface IdentitySandboxServiceContract
{
    public function allowed(User $user): bool;

    public function canGrant(User $admin, User $recipient): bool;

    public function grant(User $admin, User $recipient, string $reason): void;

    /** @return array{price_minor: int, currency: string, provider_ready: bool, can_claim: bool, credits: list<array{uuid: string, status: string, source: string, can_gift: bool}>} */
    public function summary(User $user): array;

    /** @return array{items: list<array{id: int, event: string, occurred_at: string}>, page: int, last_page: int} */
    public function history(User $user, int $page): array;

    public function claim(User $user): void;

    /** @return array{code: string, expires_at: string} */
    public function giftCode(User $user): array;

    public function gift(User $user, string $credit, string $code): void;

    public function start(User $user, string $credit, string $locale): string;

    public function refresh(User $user, string $credit): void;
}
