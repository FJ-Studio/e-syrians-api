<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Enums\ProfileChangeTypeEnum;
use App\Services\StrService;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            $user->uuid = Str::uuid();
            $user->handleHashing([
                'national_id' => 'national_id_hashed',
                'email' => 'email_hashed',
                'phone' => 'phone_hashed',
            ]);
        });
        static::updating(function ($user) {
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
        'password',
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
     * When a user handovers weapon(s)
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function handovers()
    {
        return $this->hasMany(WeaponDelivery::class, 'citizen_id', 'id');
    }

    /**
     * When an authorized user adds weapon(s) to the system
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function received_items()
    {
        return $this->hasMany(WeaponDelivery::class, 'added_by', 'id');
    }

    /**
     * Get the verifications that this user has received
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
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
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function verifications()
    {
        return $this->hasMany(UserVerification::class, 'verifier_id', 'id');
    }

    /**
     * Get the polls that this user has created
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function polls()
    {
        return $this->hasMany(Poll::class, 'created_by', 'id');
    }

    /**
     * Get the votes that this user has cast
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function votes()
    {
        return $this->hasMany(PollVote::class, 'user_id', 'id');
    }

    /**
     * Get the reactions that this user has made
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reactions()
    {
        return $this->hasMany(PollReaction::class);
    }

    /**
     * Get the profile update that this user has made
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function profileUpdates()
    {
        return $this->hasMany(ProfileUpdate::class, 'user_id', 'id');
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
    public function getTotalUpdatesCount(string $change_Type)
    {
        return $this->profileUpdates()->where(
            'change_type',
            $change_Type
        )->count();
    }

    public function getAddressUpdatesCount()
    {
        return $this->profileUpdates()
            ->where('change_type', ProfileChangeTypeEnum::Address->value)
            ->where('created_at', '>=', now()->subYear())
            ->count();
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

    public function isInAudience(array $audience): array
    {
        $failes = [];
        // age check
        if (isset($audience['age_range'])) {
            if ($audience['age_range']['min'] && Carbon::parse($this->birth_date)->diffInYears(now()) < $audience['age_range']['min']) {
                // return [false, 'age_min'];
                $failes[] = 'age_min';
            }

            if ($audience['age_range']['max'] && Carbon::parse($this->birth_date)->diffInYears(now()) > $audience['age_range']['max']) {
                // return [false, 'age_max'];
                $failes[] = 'age_max';
            }
        }

        $criteria = ['country', 'religious_affiliation', 'hometown', 'gender', 'ethnicity'];
        foreach ($criteria as $criterion) {
            if (isset($audience[$criterion])) {
                // if this criteria has values
                if (count($audience[$criterion]) > 0) {
                    if (! $this->{$criterion} || ! in_array($this->{$criterion}, $audience[$criterion])) {
                        // return [false, $criterion];
                        $failes[] = $criterion;
                    }
                }
            }
        }

        return [count($failes) === 0, $failes];
    }
}
