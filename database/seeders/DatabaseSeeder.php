<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public const COMPANIES = [
        ['HICOM Automotives', 'HAUTO', '#4FD1C5'],
        ['HICOM Diecastings', 'HDC', '#4299E1'],
        ['PHN Industry', 'PHN', '#9F7AEA'],
        ['HICOM-Teck See', 'HTS', '#ED8936'],
        ['HICOM Engineering', 'HENG', '#F56565'],
    ];

    public function run(): void
    {
        $this->call(FinancialLineSeeder::class);

        foreach (self::COMPANIES as [$name, $code, $color]) {
            $company = Company::updateOrCreate(['code' => $code], ['name' => $name, 'color' => $color, 'is_active' => true]);

            User::updateOrCreate(['email' => Str::lower($code).'@hesb.test'], [
                'name' => "{$name} Finance",
                'password' => 'password',
                'role' => Role::CompanyUser,
                'company_id' => $company->id,
                'email_verified_at' => now(),
            ]);
        }

        User::updateOrCreate(['email' => 'admin@hesb.test'], [
            'name' => 'HESB Admin', 'password' => 'password', 'role' => Role::Admin, 'email_verified_at' => now(),
        ]);
        User::updateOrCreate(['email' => 'ceo@hesb.test'], [
            'name' => 'Group CEO', 'password' => 'password', 'role' => Role::Ceo, 'email_verified_at' => now(),
        ]);

        $this->call(SampleDataSeeder::class);
    }
}
