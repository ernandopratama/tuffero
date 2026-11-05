<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property list<string>|null $mfa_recovery_codes
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'phone', 'phone_verified_at', 'mfa_totp_secret', 'mfa_totp_last_timestep', 'mfa_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

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
            'is_active' => 'boolean',
            'mfa_required' => 'boolean',
            'mfa_totp_secret' => 'encrypted',
            'mfa_totp_confirmed_at' => 'datetime',
            'mfa_email_enabled' => 'boolean',
            'mfa_recovery_codes' => 'array',
        ];
    }

    /** @return HasMany<CustomerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return array<string, mixed> */
    public function accessProfile(): array
    {
        $this->unsetRelation('roles');
        $this->unsetRelation('permissions');
        $roles = $this->getRoleNames()->values();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $roles->first(),
            'roles' => $roles,
            'permissions' => $this->getAllPermissions()->pluck('name')->sort()->values(),
        ];
    }
}
