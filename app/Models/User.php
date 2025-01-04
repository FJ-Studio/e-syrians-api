<?php
declare(strict_types = 1);
namespace App\Models;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\AutoUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Translatable\HasTranslations;
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use Notifiable;
    use HasApiTokens;
    use HasRoles;
    use AutoUuid;
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug' ,
        'name' ,
        'name_hashed' ,
        'middle_name' ,
        'middle_name_hashed' ,
        'last_name' ,
        'last_name_hashed' ,
        'brief_name' ,
        'national_id' ,
        'national_id_hashed' ,
        'gender' ,
        'birth_date' ,
        'hometown' ,
        'address' ,
        'address_hashed' ,
        'monthly_income' ,
        'phone' ,
        'phone_hashed' ,
        'email' ,
        'email_hashed' ,
        'email_verified_at' ,
        'password' ,
        'phone_verified_at' ,
        'photo' ,
        'country' ,
        'city' ,
        'shelter' ,
        'social_avatar' ,
        'google_id' ,
        'education_level' ,
        'skills' ,
        'current_source_income' ,
        'estimated_monthly_income' ,
        'estimated_monthly_income_hashed' ,
        'number_of_dependents' ,
        'health_status' ,
        'health_insurance' ,
        'easy_access_to_healthcare_services' ,
        'communication' ,
        'more_info' ,
        'religious_affiliation' ,
        'other_nationalities' ,
        'languages' ,
        'verified_at' ,
        'marked_as_fake_at' ,
        'marked_as_fake_reason'
    ];
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password' ,
        'remember_token' ,
    ];
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime' ,
            'phone_verified_at' => 'datetime' ,
            'verified_at' => 'datetime' ,
            'birth_date' => 'date' ,
            'password' => 'hashed' ,
            'address' => 'encrypted' ,
        ];
    }
    public function handovers()
    {
        return $this->hasMany(WeaponDelivery::class , 'citizen_id' , 'id');
    }
    public function received_items()
    {
        return $this->hasMany(WeaponDelivery::class , 'added_by' , 'id');
    }
    // scope get verified users
    public function scopeVerified($query)
    {
        return $query->whereNotNull('verified_at');
    }
    public function scopeMarkedAsFake($query)
    {
        return $query->whereNotNull('marked_as_fake_at');
    }
    public function verifiers()
    {
        return $this->belongsToMany(self::class , 'user_verified' , 'user_id' , 'verified_by')
            ->withTimestamps()
            ->withPivot([
                'ip_address' ,
                'user_agent' ,
            ]);
    }
    public function markAsVerified(): void
    {
        $this->update([
            'verified_at' => now() ,
        ]);
    }
    public function markAsFake(string $reason): void
    {
        $this->update([
            'marked_as_fake_at' => now() ,
            'marked_as_fake_reason' => $reason ,
        ]);
    }
}
