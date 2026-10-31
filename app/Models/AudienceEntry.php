<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\StrService;
use Illuminate\Support\Carbon;
use App\Enums\AudienceEntryTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One identifier inside an audience — either an email or a Syrian
 * national ID. The raw value is encrypted at rest and paired with
 * a hashed lookup column so vote checks + resolution refresh can
 * work without decrypting.
 *
 * @property int $id
 * @property int $audience_id
 * @property string $identifier          decrypted plaintext via cast
 * @property string $identifier_hashed   populated automatically on save
 * @property AudienceEntryTypeEnum $identifier_type
 * @property int|null $resolved_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AudienceEntry extends Model
{
    protected $fillable = [
        'identifier',
        'identifier_hashed',
        'identifier_type',
        'resolved_user_id',
        // audience_id set by the parent via ->entries()->create() —
        // never mass-assigned from a request.
    ];

    protected $casts = [
        'identifier' => 'encrypted',
        'identifier_type' => AudienceEntryTypeEnum::class,
    ];

    /**
     * Populate `identifier_hashed` from `identifier` on every save
     * where the identifier changed. Mirrors User's PII handling —
     * caller doesn't need to hash manually, the model does the
     * right thing whether the entry is created fresh or an update
     * changes the underlying identifier.
     */
    protected static function boot(): void
    {
        parent::boot();

        $syncHash = function (AudienceEntry $entry): void {
            $identifier = $entry->getAttribute('identifier');
            if ($identifier === null || $identifier === '') {
                return;
            }
            $entry->identifier_hashed = StrService::hash((string) $identifier);
        };

        static::creating($syncHash);
        static::updating(function (AudienceEntry $entry) use ($syncHash): void {
            // Only re-hash if the plaintext actually changed.
            if ($entry->isDirty('identifier')) {
                $syncHash($entry);
            }
        });
    }

    /** @return BelongsTo<Audience, $this> */
    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    /**
     * The user this entry currently resolves to, if any. Cached
     * on `resolved_user_id`; the cache is populated at add time
     * and refreshed on demand via the manual "Refresh resolution"
     * button. A null value means "no registered user matches this
     * identifier at last check" — not "hasn't been checked yet".
     */
    public function resolvedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_user_id');
    }
}
