<?php

namespace App\Enums;

enum Role: string
{
    case CompanyUser = 'company_user';
    case Admin = 'hesb_admin';
    case Ceo = 'ceo';

    public function label(): string
    {
        return match ($this) {
            self::CompanyUser => 'Company User',
            self::Admin => 'HESB Admin',
            self::Ceo => 'CEO',
        };
    }

    /** Group-level roles see every company. */
    public function isGroupLevel(): bool
    {
        return $this !== self::CompanyUser;
    }
}
