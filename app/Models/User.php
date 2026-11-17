<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasUuids;
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    public $incrementing = false;
    public $primaryKey = 'uuid';
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'merchant_name',
        'first_name',
        'middle_name',
        'last_name',
        'contact_number',
        'address',
        'email',
        'password',
        'is_platform_admin',
        'is_marketing',
        'account_status',
        'trial_ends_at',
        'getting_started_dismissed_at',
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
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'is_marketing' => 'boolean',
            'trial_ends_at' => 'date',
            'getting_started_dismissed_at' => 'datetime',
        ];
    }

    public function onFreeTrial(?\DateTimeInterface $asOf = null): bool
    {
        if (! $this->trial_ends_at) {
            return false;
        }

        $day = \Illuminate\Support\Carbon::parse($asOf ?? now())->startOfDay();

        return $day->lte($this->trial_ends_at->copy()->startOfDay());
    }

    public function trialDaysRemaining(?\DateTimeInterface $asOf = null): int
    {
        if (! $this->onFreeTrial($asOf)) {
            return 0;
        }

        $day = \Illuminate\Support\Carbon::parse($asOf ?? now())->startOfDay();

        return (int) $day->diffInDays($this->trial_ends_at->copy()->startOfDay());
    }

    public function scopeMerchants($query)
    {
        return $query->where('is_platform_admin', false)->where('is_marketing', false);
    }

    public function ownedProspects(): HasMany
    {
        return $this->hasMany(MarketingProspect::class, 'owner_user_uuid', 'uuid');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function ownedTeamMembers(): HasMany
    {
        return $this->hasMany(TeamMember::class, 'owner_user_uuid');
    }

    public function bookingPortalConnections(): HasMany
    {
        return $this->hasMany(BookingPortalConnection::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(MerchantSubscription::class, 'user_uuid', 'uuid');
    }
}
