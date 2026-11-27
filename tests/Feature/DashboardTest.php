<?php

namespace Tests\Feature;

use App\Models\AmpTarget;
use App\Models\Company;
use App\Models\Entry;
use App\Models\FinancialLine;
use App\Services\Finance\FinanceGuru;
use App\Services\Finance\PerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-03-16 09:00'); // Wk3 of March → Wk1-2 complete
        $this->w = $this->world();
    }

    private function id(string $code): int
    {
        return FinancialLine::standard()->where('code', $code)->value('id');
    }

    private function amp(Company $c, string $code, string $amount): void
    {
        for ($m = 1; $m <= 12; $m++) {
            AmpTarget::create(['company_id' => $c->id, 'year' => 2026, 'month' => $m, 'financial_line_id' => $this->id($code), 'amount' => $amount]);
        }
    }

    private function entry(Company $c, int $m, int $wk, string $code, ?string $act, ?string $fc = null): void
    {
        Entry::create(['company_id' => $c->id, 'year' => 2026, 'month' => $m, 'week' => $wk,
            'financial_line_id' => $this->id($code), 'actual' => $act, 'forecast' => $fc]);
    }

    #[Test]
    public function amp_to_date_is_prorated_by_completed_weeks(): void
    {
        $a = $this->w['a'];
        $this->amp($a, 'revenue', '1000.00');
        $this->amp($a, 'direct_materials', '600.00');
        $this->entry($a, 1, 1, 'revenue', '2000.00');
        $this->entry($a, 2, 1, 'revenue', '400.00');
        $this->entry($a, 1, 1, 'direct_materials', '1500.00');

        $d = app(PerformanceService::class)->dashboard(collect([$a]), 2026);

        // Jan + Feb + 2/5 of Mar = 2,400
        $rev = $d['kpis']['total_revenue'];
        $this->assertSame('2400.00', $rev['actual']);
        $this->assertSame('2400.00', $rev['amp']);
        $this->assertSame('100.00', $rev['pct']);
        $this->assertSame('good', $rev['status']);
        $this->assertSame('12000.00', $rev['fullYear']);

        // GP: 2400 − 1500 = 900 vs AMP 2400 − 1440 = 960 → 93.75%
        $gp = $d['kpis']['gross_profit'];
        $this->assertSame('900.00', $gp['actual']);
        $this->assertSame('960.00', $gp['amp']);
        $this->assertSame('93.75', $gp['pct']);
        $this->assertSame('bad', $gp['status']);

        // Margin 37.50% vs 40.00% → −2.50 pts
        $this->assertSame('37.50', $d['kpis']['gross_margin']['actual']);
        $this->assertSame('40.00', $d['kpis']['gross_margin']['amp']);
        $this->assertSame('-2.50', $d['kpis']['gross_margin']['delta']);

        // Chart series per month
        $this->assertSame('2000.00', $d['chart']['series']['total_revenue']['actual'][0]);
        $this->assertSame('1000.00', $d['chart']['series']['total_revenue']['amp'][11]);
    }

    #[Test]
    public function flash_uses_last_finished_week_and_flags_missing_forecasts(): void
    {
        $a = $this->w['a'];
        $this->entry($a, 3, 1, 'revenue', '90.00');
        $this->entry($a, 3, 2, 'revenue', '100.00');
        $this->entry($a, 3, 3, 'revenue', null, '110.00');
        // Wk4 forecast missing

        $flash = app(PerformanceService::class)->dashboard(collect([$a]), 2026)['flash'];

        $this->assertSame('Wk2 Mar Act', $flash['labels']['actual']);
        $this->assertSame('Wk3 Mar Fc', $flash['labels']['fc1']);
        $this->assertSame('Wk4 Mar Fc', $flash['labels']['fc2']);
        $this->assertSame('100.00', $flash['rows']['total_revenue']['actual']);
        $this->assertSame('110.00', $flash['rows']['total_revenue']['fc1']);
        $this->assertNull($flash['rows']['total_revenue']['fc2']);
        $this->assertSame('11.11', $flash['rows']['total_revenue']['change']); // 100 vs 90
        $this->assertCount(1, $flash['pendingForecast']);
        $this->assertCount(0, $flash['pendingActual']);
    }

    #[Test]
    public function the_group_view_consolidates_companies(): void
    {
        ['a' => $a, 'b' => $b] = $this->w;
        $this->amp($a, 'revenue', '100.00');
        $this->amp($b, 'revenue', '300.00');
        $this->entry($a, 1, 1, 'revenue', '150.00');
        $this->entry($b, 1, 1, 'revenue', '250.00');

        $d = app(PerformanceService::class)->dashboard(collect([$a, $b]), 2026);
        $this->assertSame('400.00', $d['kpis']['total_revenue']['actual']);
        $this->assertSame('960.00', $d['kpis']['total_revenue']['amp']); // 400 × 2.4 months
    }

    #[Test]
    public function the_advisor_flags_revenue_gaps_and_pending_forecasts(): void
    {
        $a = $this->w['a'];
        $this->amp($a, 'revenue', '1000.00');
        $this->entry($a, 1, 1, 'revenue', '1000.00');
        $this->entry($a, 2, 1, 'revenue', '800.00');
        $this->entry($a, 3, 2, 'revenue', '100.00');

        $d = app(PerformanceService::class)->dashboard(collect([$a]), 2026);
        $tips = collect(app(FinanceGuru::class)->advise($d));

        // 1,900 vs 2,400 → 20.8% below
        $rev = $tips->firstWhere(fn ($t) => str_starts_with($t['title'], 'Revenue'));
        $this->assertSame('bad', $rev['level']);
        $this->assertSame('Revenue 20.8% below AMP', $rev['title']);
        $this->assertNotNull($tips->firstWhere('title', 'Forecast submission pending'));
        // Most severe first
        $this->assertSame('bad', $tips->first()['level']);
    }

    #[Test]
    public function the_company_dashboard_renders_gauges_flash_chart_and_advice(): void
    {
        $a = $this->w['a'];
        $this->amp($a, 'revenue', '1000.00');
        $this->entry($a, 3, 2, 'revenue', '500.00');

        $this->actingAs($this->w['userA'])->get('/dashboard')->assertOk()
            ->assertSee('Revenue')
            ->assertSee('of AMP to date')
            ->assertSee('Financial flash')
            ->assertSee('Forecast Submission Pending')
            ->assertSee('Finance Guru Advisory')
            ->assertSee('Monthly performance 2026')
            ->assertSee('Manage Forecast &amp; Actual', false);

        // Bad year input falls back safely.
        $this->get('/dashboard?year=abc')->assertOk()->assertSee('Monthly performance 2026');
        $this->get('/dashboard?year=2025')->assertOk()->assertSee('Monthly performance 2025');
    }

    #[Test]
    public function drill_down_shows_the_same_dashboard_to_admin_and_ceo(): void
    {
        $this->entry($this->w['b'], 3, 2, 'revenue', '321.00');

        foreach (['admin', 'ceo'] as $role) {
            $this->actingAs($this->w[$role])->get("/group/companies/{$this->w['b']->id}")
                ->assertOk()->assertSee('Bravo Sdn Bhd')->assertSee('321.00')->assertSee('Company drill-down');
        }
    }
}
