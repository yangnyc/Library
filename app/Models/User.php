<?php

namespace App\Models;

use App\Modules\Accounts\Models\ReadingList;
use App\Modules\Catalog\Models\Work;
use App\Modules\Reader\Models\Annotation;
use App\Modules\Reader\Models\Bookmark;
use App\Modules\Reader\Models\ReadingProgress;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

// `role` is deliberately not fillable: it is only ever set explicitly by an
// administrator action or the library:create-admin console command.
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    public const ROLES = ['reader', 'editor', 'admin'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'reader_preferences' => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Editors and administrators may use the administration area. */
    public function isStaff(): bool
    {
        return in_array($this->role, ['editor', 'admin'], true);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Email verification is only required when mail is actually configured;
     * otherwise nobody could ever complete it.
     */
    public function hasVerifiedEmail(): bool
    {
        return ! config('library.mail_enabled') || $this->email_verified_at !== null;
    }

    public function sendEmailVerificationNotification(): void
    {
        if (config('library.mail_enabled')) {
            parent::sendEmailVerificationNotification();
        }
    }

    public function readingProgress(): HasMany
    {
        return $this->hasMany(ReadingProgress::class);
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class);
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class);
    }

    public function readingLists(): HasMany
    {
        return $this->hasMany(ReadingList::class);
    }

    public function favorites(): BelongsToMany
    {
        return $this->belongsToMany(Work::class, 'favorites')->withPivot('created_at');
    }
}
