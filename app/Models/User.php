<?php

namespace App\Models;

use App\Models\Concerns\UsesDefaultConnectionWhenTesting;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

/**
 * Mengimplementasikan kontrak bawaan Laravel MustVerifyEmail: verifikasi email
 * berjalan lewat pipeline standard (notifikasi VerifyEmail → signed URL ke
 * route web 'verification.verify') via sendEmailVerificationNotification().
 */
class User extends Authenticatable implements FilamentUser, MustVerifyEmailContract
{
    use HasApiTokens;
    use HasFactory;
    use HasPanelShield;
    use HasProfilePhoto;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;
    use UsesDefaultConnectionWhenTesting;

    protected $connection = 'sagansa_user';

    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = ['name', 'email', 'password', 'google_id', 'apple_id', 'avatar'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = ['profile_photo_url'];

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
        ];
    }

    /**
     * Get all of the chargerLocations.
     *
     * @return HasMany
     */
    public function chargerLocations()
    {
        return $this->hasMany(ChargerLocation::class);
    }

    public function userSubscriptions()
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function chargers()
    {
        return $this->hasMany(Charger::class);
    }

    /**
     * Get all of the stateOfHealths.
     *
     * @return HasMany
     */
    public function stateOfHealths()
    {
        return $this->hasMany(StateOfHealth::class);
    }

    /**
     * Get all of the vehicles.
     *
     * @return HasMany
     */
    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * Get all of the batteries.
     *
     * @return HasMany
     */
    public function batteries()
    {
        return $this->hasMany(Battery::class);
    }

    /**
     * Get all of the service logs (catatan servis kendaraan, fitur Pro).
     *
     * @return HasMany
     */
    public function serviceLogs()
    {
        return $this->hasMany(ServiceLog::class);
    }

    /**
     * Get all of the charges.
     *
     * @return HasMany
     */
    public function charges()
    {
        return $this->hasMany(Charge::class);
    }

    /**
     * Get all of the discountHomeChargings.
     *
     * @return HasMany
     */
    public function discountHomeChargings()
    {
        return $this->hasMany(DiscountHomeCharging::class);
    }

    public function contributorProfile()
    {
        return $this->hasOne(ContributorProfile::class);
    }

    public function locationReports()
    {
        return $this->hasMany(LocationReport::class, 'reporter_id');
    }

    public function locationUpdates()
    {
        return $this->hasMany(LocationUpdate::class, 'contributor_id');
    }

    public function verifiedLocations()
    {
        return $this->hasMany(ChargerLocation::class, 'verified_by');
    }

    public function processedReports()
    {
        return $this->hasMany(LocationReport::class, 'processed_by');
    }

    public function approvedUpdates()
    {
        return $this->hasMany(LocationUpdate::class, 'approved_by');
    }

    public function obdAccess()
    {
        return $this->hasOne(ObdAccess::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->hasRole('super_admin')) {
            return true;
        }

        if ($panel->getId() === 'admin') {
            return $this->hasRole('admin');
        }

        if ($panel->getId() === 'user') {
            return $this->hasRole('user');
        }

        return false;
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function () {
            static::ensureDefaultRoleExists();
        });

        static::created(function (User $user) {
            static::assignDefaultRole($user);
        });
    }

    protected static function ensureDefaultRoleExists(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');
        $role = Role::query()->firstOrCreate([
            'name' => 'user',
            'guard_name' => $guard,
        ]);

        if ($role->wasRecentlyCreated) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    protected static function assignDefaultRole(User $user): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        if (! $user->hasRole('user')) {
            $user->assignRole('user');
        }
    }
}
