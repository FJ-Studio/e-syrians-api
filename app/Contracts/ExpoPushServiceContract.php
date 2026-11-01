<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Channels\Messages\ExpoPushMessage;

interface ExpoPushServiceContract
{
    public function isEnabled(): bool;

    public function send(string $token, ExpoPushMessage $message): string;

    /** @return array{status:string, details?:array{error?:string}}|null */
    public function receipt(string $receiptId): ?array;
}
