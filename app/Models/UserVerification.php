<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserVerification extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'verifier_id',
        'user_id',
        'cancelled_at',
        'cancelation_payload',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
        'cancelation_payload' => 'array',
    ];

    /**
     * The user being verified (the recipient).
     *
     * Declared return type so PHPStan/Larastan can resolve
     * `$verification->user` to a `User` model — without the type
     * hint the cancel flow's
     * `$recipient = $verification->user; $recipient->activeVerifiers()…`
     * tripped Larastan with "Access to an undefined property".
     *
     * The second generic is `$this`, not `UserVerification`, so it
     * stays correct under subclassing — Larastan rejects the
     * concrete-class form (see app/Models/Poll.php for the pattern
     * the rest of the codebase uses).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user who issued the verification.
     *
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verifier_id');
    }
}
