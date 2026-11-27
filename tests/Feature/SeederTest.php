<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AmpTarget;
use App\Models\Company;
use App\Models\Entry;
use App\Models\FinancialLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_companies_users_lines_and_2026_data(): void
    {
        $this->travelTo('2026-11-16 09:00');
        $this->seed();

        $this->assertSame(5, Company::count());
        $this->assertSame(14, FinancialLine::standard()->count());
        $this->assertSame(1, User::where('role', Role::Admin)->count());
        $this->assertSame(1, User::where('role', Role::Ceo)->count());
        $this->assertSame(5, User::where('role', Role::CompanyUser)->whereNotNull('company_id')->count());

        // 5 companies × 12 months × 10 input lines
        $this->assertSame(600, AmpTarget::where('year', 2026)->count());

        $heng = Company::where('code', 'HENG')->first();
        $hauto = Company::where('code', 'HAUTO')->first();

        // Week 3 of Nov (current week): forecast present, actual not yet.
        $cell = Entry::where(['company_id' => $hauto->id, 'year' => 2026, 'month' => 11, 'week' => 3])->first();
        $this->assertNotNull($cell->forecast);
        $this->assertNull($cell->actual);

        // HENG has not submitted forward forecasts → pending badge demo.
        $this->assertSame(0, Entry::where(['company_id' => $heng->id, 'year' => 2026, 'month' => 11, 'week' => 3])->count());

        // Seeding twice is idempotent.
        $this->seed();
        $this->assertSame(5, Company::count());
        $this->assertSame(600, AmpTarget::where('year', 2026)->count());
    }
}
