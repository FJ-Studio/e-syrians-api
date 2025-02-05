<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Services\StrService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use Notifiable;
    use HasApiTokens;
    use HasRoles;
    use SoftDeletes;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            $user->uuid = Str::uuid();
            $user->handleHashing([
                'national_id' => 'national_id_hash',
                'email' => 'email_hashed',
                'phone' => 'phone_hashed',
            ]);
        });
        static::updating(function ($user) {
            $user->handleHashing([
                'national_id' => 'national_id_hash',
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
        'national_id_hash',
        'gender',
        'birth_date',
        'hometown',
        'email',
        'email_hashed',
        'phone',
        'phone_hashed',
        'social_avatar',
        'google_id',
        'password',
        'country',
        'city',
        'shelter',
        'address',
        'email_verified_at',
        'phone_verified_at',
        'photo',
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
    ];

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
     * @param array<string> $fields
     * @param bool $checkDirty
     * @return void
     */
    public function handleHashing(array $fields, bool $checkDirty = false)
    {
        foreach ($fields as $original => $hashed) {
            if ($checkDirty && !$this->isDirty($original)) {
                continue;
            }
            if (!empty($this->{$original})) {
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
}
