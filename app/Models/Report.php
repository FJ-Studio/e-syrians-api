<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportReasonEnum;
use App\Enums\ReportStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    protected $fillable = ['reported_by', 'reason', 'details'];

    protected $attributes = ['status' => 'pending'];

    protected $casts = [
        'reason' => ReportReasonEnum::class,
        'status' => ReportStatusEnum::class,
        'reviewed_at' => 'datetime',
    ];

    /** @return MorphTo<Model, $this> */
    public function reportable(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScope('public_polls')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
