<?php

namespace Tests;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\FinancialLineSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * Two companies with one user each, plus admin and CEO.
     *
     * @return array{a: Company, b: Company, userA: User, userB: User, admin: User, ceo: User}
     */
    protected function world(): array
    {
        $this->seed(FinancialLineSeeder::class);
        $a = Company::factory()->create(['name' => 'Alpha Sdn Bhd', 'code' => 'ALPHA']);
        $b = Company::factory()->create(['name' => 'Bravo Sdn Bhd', 'code' => 'BRAVO']);

        return [
            'a' => $a,
            'b' => $b,
            'userA' => User::factory()->forCompany($a)->create(),
            'userB' => User::factory()->forCompany($b)->create(),
            'admin' => User::factory()->admin()->create(),
            'ceo' => User::factory()->ceo()->create(),
        ];
    }
}
