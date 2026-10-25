<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Un compte joueur.
 *
 * is_admin, banned_*, muted_until ne sont PAS dans Fillable : un formulaire d'inscription
 * ne doit jamais pouvoir les remplir. On les change avec forceFill() (commande game:admin, outils d'admin).
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
            'is_admin' => 'boolean',
            'banned_at' => 'datetime',
            'banned_until' => 'datetime',
            'muted_until' => 'datetime',
        ];
    }

    public function characters()
    {
        return $this->hasMany(\App\Models\Character::class)->orderBy('slot');
    }

    // ------------------------------------------------------------------
    // Modération (étape 10)
    // ------------------------------------------------------------------

    /** Banni en ce moment ? (définitif si banned_until est vide) */
    public function isBanned(): bool
    {
        return $this->banned_at !== null && ($this->banned_until === null || $this->banned_until->isFuture());
    }

    /** "définitivement" ou "jusqu'au 18/10/2026 à 21:30" */
    public function banEndText(): string
    {
        return $this->banned_until ? 'jusqu\'au ' . $this->banned_until->format('d/m/Y à H:i') : 'définitivement';
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    public function ban(?\DateTimeInterface $until, ?string $reason): void
    {
        $this->forceFill(['banned_at' => now(), 'banned_until' => $until, 'ban_reason' => $reason])->save();
    }

    public function unban(): void
    {
        $this->forceFill(['banned_at' => null, 'banned_until' => null, 'ban_reason' => null])->save();
    }
}
