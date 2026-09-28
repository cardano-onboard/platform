<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Reserved email for the tombstone account that inherits campaigns from
     * deleted users. It has an unguessable password and is never registered
     * through, so it cannot be logged into.
     */
    public const DELETED_PLACEHOLDER_EMAIL = 'deleted@onboard.ninja';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /*
     * is_admin is deliberately absent from $fillable. Registration builds its create()
     * array from request input, so a mass-assignable flag would let anyone registering
     * grant themselves the operator view by posting one extra field.
     */

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'boolean',
    ];

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * The tombstone account that inherits campaigns when a user hard-deletes
     * their account, so on-chain campaign history (wallets, codes, claims) is
     * retained for audit and dispute resolution. Created on first use.
     */
    public static function deletedPlaceholder(): self
    {
        return static::firstOrCreate(
            ['email' => self::DELETED_PLACEHOLDER_EMAIL],
            [
                'name' => 'Deleted User',
                'password' => Hash::make(Str::random(64)),
                'email_verified_at' => now(),
            ],
        );
    }

    public function wallets(): HasManyThrough
    {
        return $this->hasManyThrough(Wallet::class, Campaign::class);
    }
}
