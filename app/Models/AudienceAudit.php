<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Event log row emitted whenever an audience is edited while at
 * least one active poll (currently inside its voting window)
 * references it. Purely for moderation / dispute resolution — no
 * user-facing surface today; queried via the Filament
 * AudienceAuditResource.
 *
 * See the create_audience_audits_table migration header for the
 * full rationale and emission rule.
 *
 * @property int $id
 * @property int $audience_id
 * @property int $edited_by_user_id
 * @property array<int, int> $affected_poll_ids
 * @property int $entries_added_count
 * @property int $entries_removed_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AudienceAudit extends Model
{
    protected $fillable = [
        'audience_id',
        'edited_by_user_id',
        'affected_poll_ids',
        'entries_added_count',
        'entries_removed_count',
    ];

    protected $casts = [
        'affected_poll_ids' => 'array',
        'entries_added_count' => 'integer',
        'entries_removed_count' => 'integer',
    ];

    /** @return BelongsTo<Audience, $this> */
    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_user_id');
    }
}
