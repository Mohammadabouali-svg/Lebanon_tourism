<?php

namespace App\Models;

use App\Enums\OperatorStatus;
use App\Enums\UserRole;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/*
| Mass assignment: only the columns listed in #[Fillable] can be filled from
| an array (User::create($data), $user->update($data)).
|
| `role` is deliberately NOT fillable. Even if an attacker adds
| `"role": "admin"` to the registration request, it is ignored. Code that
| really wants to change a role has to say so explicitly:
|     $user->role = UserRole::Admin;
|
| `email` is fillable because sign-up sets it from validated input. Changing
| it later is NOT a profile update: it goes through EmailChangeService,
| which keeps the new address in the non-fillable `pending_email` until the
| link mailed to it is clicked (D20).
*/
#[Fillable(['name', 'email', 'password', 'phone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /*
    | Default values for a NEW model, mirroring the database defaults.
    |
    | Without them, `new User(...)->save()` leaves these keys out of the
    | in-memory model (the database fills them, but the PHP object does not
    | know). Strict mode (see AppServiceProvider) then throws when code reads
    | `$user->suspended_at`, instead of silently returning null. That error
    | is how this "silent" gap was found.
    */
    protected $attributes = [
        'role' => 'traveler',
        'phone' => null,
        'email_verified_at' => null,
        'pending_email' => null,
        'suspended_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            // 'hashed' runs password_hash() automatically when a plain
            // password is assigned. It is impossible to forget.
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    protected static function booted(): void
    {
        // Every account gets a wallet as soon as it is created. The rest of
        // the code can then rely on `$user->wallet` existing, with no
        // "if wallet missing, create it" checks scattered around.
        static::created(function (User $user): void {
            $user->wallet()->create();
        });
    }

    /** Normalise e-mails so uniqueness and login are case-insensitive. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    /** Same normalisation, so the pending address compares equal to a future `email`. */
    protected function pendingEmail(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    // --- Relationships ------------------------------------------------------

    public function operatorProfile(): HasOne
    {
        return $this->hasOne(OperatorProfile::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** Tours created by this user (operators only). */
    public function tours(): HasMany
    {
        return $this->hasMany(Tour::class, 'operator_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    // --- Role helpers -------------------------------------------------------

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isOperator(): bool
    {
        return $this->role === UserRole::Operator;
    }

    public function isTraveler(): bool
    {
        return $this->role === UserRole::Traveler;
    }

    public function isApprovedOperator(): bool
    {
        return $this->isOperator()
            && $this->operatorProfile?->status === OperatorStatus::Approved;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    // --- Notifications ------------------------------------------------------
    // Laravel's default e-mails link to Laravel routes. Ours link to pages of
    // the React app, which then call the API. See app/Notifications.

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
