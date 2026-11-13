<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property int $owner_id
 * @property string $status
 * @property string $source
 * @property ?string $attempt_uuid
 * @property ?string $provider_session_id
 * @property ?string $provider_url
 * @property ?Carbon $gifted_at
 */
class IdentitySandboxCredit extends Model
{
    public const SOURCE_SELF_CLAIM = 'self_claim';

    public const SOURCE_ADMIN_GRANT = 'admin_grant';

    public const SOURCE_PURCHASE = 'purchased';

    protected $fillable = ['uuid', 'issued_to_user_id', 'owner_id'];

    protected $hidden = ['attempt_uuid', 'provider_session_id', 'provider_url', 'granted_by_user_id', 'grant_reason'];

    public function canGift(): bool
    {
        return $this->status === 'available' && $this->isTransferableSource() && $this->gifted_at === null;
    }

    public function isTransferableSource(): bool
    {
        return $this->source === self::SOURCE_SELF_CLAIM;
    }

    protected function casts(): array
    {
        return ['gifted_at' => 'datetime', 'provider_url' => 'encrypted'];
    }
}
