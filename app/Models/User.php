<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'company_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isCeo(): bool
    {
        return $this->role === Role::Ceo;
    }

    public function isCompanyUser(): bool
    {
        return $this->role === Role::CompanyUser;
    }

    public function isGroupLevel(): bool
    {
        return $this->role->isGroupLevel();
    }

    /** Can this user view the given company's data at all? */
    public function canViewCompany(Company|int $company): bool
    {
        $id = $company instanceof Company ? $company->id : $company;

        return $this->isGroupLevel() || ($this->company_id !== null && $this->company_id === $id);
    }
}
