<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Str;
use App\Services\StrService;
use Laravel\Sanctum\HasApiTokens;
use Database\Factories\UserFactory;
use App\Enums\ProfileChangeTypeEnum;
use Illuminate\Support\Facades\Date;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user): void {
            $user->uuid = Str::uuid();
            $user->handleHashing([
                'national_id' => 'national_id_hashed',
                'email' => 'email_hashed',
                'phone' => 'phone_hashed',
            ]);
        });
        static::updating(function ($user): void {
            $user->handleHashing([
                'national_id' => 'national_id_hashed',
                'email' => 'email_hashed',
                'phone' => 'phone_hashed',
            ], true);
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'middle_name',
        'surname',
        'national_id',
        'national_id_hashed',
        'gender',
        'birth_date',
        'hometown',
        'email',
        'email_hashed',
        'phone',
        'phone_hashed',
        'avatar',
        'google_id',
        'country',
        'city',
        'shelter',
        'address',
        'email_verified_at',
        'phone_verified_at',
        'education_level',
        'skills',
        'marital_status',
        'source_of_income',
        'estimated_monthly_income',
        'number_of_dependents',
        'health_status',
        'health_insurance',
        'easy_access_to_healthcare_services',
        'religious_affiliation',
        'communication',
        'more_info',
        'other_nationalities',
        'languages',
        'verified_at',
        'verification_reason',
        'marked_as_fake_at',
        'marked_as_fake_reason',
        'record_place',
        'record_id',
        'ethnicity',
        // social links
        'facebook_link',
        'github_link',
        'twitter_link',
        'linkedin_link',
        'instagram_link',
        'youtube_link',
        'tiktok_link',
        'pinterest_link',
        'twitch_link',
        'snapchat_link',
        'website',
        'received_verification_email',
        'account_verified_email',
        'city_inside_syria',
        'language',
        // Two-factor authentication
        'two_factor_secret',
        'two_factor_enabled',
        'two_factor_confirmed_at',
        'recovery_codes',
    ];

    public function getRouteKeyName()
    {
        return 'uuid'; // This tells Laravel to use 'uuid' instead of 'id' in routes
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'address' => 'encrypted',
            'national_id' => 'encrypted',
            'recovery_codes' => 'array',
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Hash the specified fields
     *
     * @param  array<string>  $fields
     * @return void
     */
    public function handleHashing(array $fields, bool $checkDirty = false)
    {
        foreach ($fields as $original => $hashed) {
            if ($checkDirty && ! $this->isDirty($original)) {
                continue;
            }
            if (! empty($this->{$original})) {
                $this->{$hashed} = StrService::hash($this->{$original});
            }
        }
    }

    /**
     * Get the verifications that this user has received
     *
     * @return HasMany
     */
    public function verifiers()
    {
        return $this->hasMany(UserVerification::class, 'user_id', 'id');
    }

    public function activeVerifiers()
    {
        return $this->verifiers()->whereNull('cancelled_at');
    }

    /**
     * Get the verifications that this user has made
     *
     * @return HasMany
     */
    public function verifications()
    {
        return $this->hasMany(UserVerification::class, 'verifier_id', 'id');
    }

    /**
     * Get the polls that this user has created
     *
     * @return HasMany
     */
    public function polls()
    {
        return $this->hasMany(Poll::class, 'created_by', 'id');
    }

    /**
     * Get the votes that this user has cast
     *
     * @return HasMany
     */
    public function votes()
    {
        return $this->hasMany(PollVote::class, 'user_id', 'id');
    }

    /**
     * Get the reactions that this user has made
     *
     * @return HasMany
     */
    public function reactions()
    {
        return $this->hasMany(PollReaction::class);
    }

    /**
     * Get the profile update that this user has made
     *
     * @return HasMany
     */
    public function profileUpdates()
    {
        return $this->hasMany(ProfileUpdate::class);
    }

    /**
     * Get the violations reported by this user
     */
    public function violations()
    {
        return $this->hasMany(Violation::class, 'user_id', 'id');
    }

    /**
     * Get the profile update that this user has made
     *
     * @return int
     */
    public function getTotalUpdatesCount(string $changeType)
    {
        return $this->profileUpdates()->where(
            'change_type',
            $changeType
        )->count();
    }

    public function getAddressUpdatesCount()
    {
        return $this->profileUpdates()
            ->where('change_type', ProfileChangeTypeEnum::Address->value)
            ->where('created_at', '>=', now()->subYear())
            ->count();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_enabled && $this->two_factor_confirmed_at !== null;
    }

    public function isVerified(): bool
    {
        return (bool) $this->verified_at;
    }

    /**
     * Reset the user's profile verification
     */
    public function markAsUnverified()
    {
        $this->verified_at = null;
        $this->save();
    }

    public function canVerify(): array
    {
        // 1. check if user is not banned
        if ($this->marked_as_fake_at) {
            return [false, 'your_account_is_banned'];
        }
        // 2. check if user is verified
        if (! $this->verified_at) {
            return [false, 'you_are_not_verified'];
        }
        // 3. check the user verifications status
        $receivedVerifications = $this->activeVerifiers()->count();
        $givenVerifications = $this->verifications()->count();
        $threshold = config('e-syrians.verification');
        // A. If the user exceeded the maximum number of verifications allowed
        if ($givenVerifications >= $threshold['max']) {
            return [false, 'you_have_reached_the_maximum_verifications'];
        }
        // B. If the user is not of the first registrants
        if ($this->verification_reason !== 'first_registrant') {
            // B. If user A does not have enough verifications
            if ($receivedVerifications < $threshold['min']) {
                return [false, 'you_do_not_have_enough_verifications'];
            }
            // C. If the difference between verifiers and number of verifications is less than the threshold
            if ($receivedVerifications - $givenVerifications < $threshold['diff']) {
                return [false, 'you_have_made_a_lot_of_verifications'];
            }
        }

        return [true, ''];
    }

    public function hasAnsweredPoll(int $pollId): bool
    {
        return $this->votes()->where('poll_id', $pollId)->exists();
    }

    public function isInAudience(Poll $poll): array
    {
        if (! $poll->relationLoaded('audienceRules')) {
            $poll->load('audienceRules');
        }

        $rules = $poll->audienceRules;
        $failures = [];

        // Allowed voters check — if specified, only match by email or national_id
        $allowedVoters = $rules->where('criterion', 'allowed_voter')->pluck('value')->all();
        if (count($allowedVoters) > 0) {
            $allowed = array_map('strtolower', $allowedVoters);
            $emailMatch = $this->email && in_array(strtolower($this->email), $allowed);
            $nationalIdMatch = $this->national_id && in_array(strtolower($this->national_id), $allowed);

            if (! $emailMatch && ! $nationalIdMatch) {
                return [false, ['not_in_allowed_voters']];
            }

            return [true, []];
        }

        // Age check
        $ageMin = $rules->where('criterion', 'age_min')->first()?->value;
        $ageMax = $rules->where('criterion', 'age_max')->first()?->value;

        if ($ageMin !== null || $ageMax !== null) {
            if (! $this->birth_date) {
                $failures[] = 'birth_date_missing';
            } else {
                $age = \Illuminate\Support\Facades\Date::parse($this->birth_date)->diffInYears(now());

                if ($ageMin !== null && $age < (int) $ageMin) {
                    $failures[] = 'age_min';
                }

                if ($ageMax !== null && $age > (int) $ageMax) {
                    $failures[] = 'age_max';
                }
            }
        }

        // Criteria checks
        $criteria = ['country', 'religious_affiliation', 'hometown', 'gender', 'ethnicity', 'city_inside_syria'];
        foreach ($criteria as $criterion) {
            $values = $rules->where('criterion', $criterion)->pluck('value')->all();
            if (count($values) > 0) {
                if (! $this->{$criterion}) {
                    $failures[] = $criterion . '_missing';
                } elseif (! in_array($this->{$criterion}, $values)) {
                    $failures[] = $criterion;
                }
            }
        }

        return [count($failures) === 0, $failures];
    }
}
