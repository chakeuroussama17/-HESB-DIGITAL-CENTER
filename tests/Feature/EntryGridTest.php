<?php

namespace Tests\Feature;

use App\Models\AmpTarget;
use App\Models\AuditLog;
use App\Models\Entry;
use App\Models\FinancialLine;
use App\Models\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EntryGridTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 10:00'); // Wk3 of November
    }

    private function line(string $code): FinancialLine
    {
        return FinancialLine::standard()->where('code', $code)->firstOrFail();
    }

    #[Test]
    public function a_company_user_saves_a_month_and_derived_rows_are_computed_not_stored(): void
    {
        $w = $this->world();
        $rev = $this->line('revenue');
        $dm = $this->line('direct_materials');
        $admin = $this->line('admin_exp');

        $this->actingAs($w['userA'])->put('/entries/2026/11', ['cells' => [
            $rev->id => [1 => ['actual' => '1,000.50', 'forecast' => '900'], 2 => ['actual' => '500']],
            $dm->id => [1 => ['actual' => '600.25'], 2 => ['actual' => '(10)']],
            $admin->id => [1 => ['actual' => '100']],
        ]])->assertRedirect('/entries/2026/11')->assertSessionHas('status');

        $this->assertDatabaseHas('entries', [
            'company_id' => $w['a']->id, 'year' => 2026, 'month' => 11, 'week' => 1,
            'financial_line_id' => $rev->id, 'actual' => '1000.50', 'forecast' => '900.00',
            'updated_by' => $w['userA']->id,
        ]);
        $this->assertSame('-10.00', Entry::where(['financial_line_id' => $dm->id, 'week' => 2])->value('actual'));
        $derivedIds = FinancialLine::where('type', 'derived')->pluck('id');
        $this->assertSame(0, Entry::whereIn('financial_line_id', $derivedIds)->count());

        // Audit: one row per changed value with old/new.
        $this->assertSame(6, AuditLog::where('action', 'entry.grid')->count());
        $this->assertDatabaseHas('audit_logs', ['field' => 'actual', 'old_value' => null, 'new_value' => '1000.50', 'user_id' => $w['userA']->id]);

        // Grid shows: Wk1 GP = 1000.50 − 600.25 = 400.25; Total Act revenue 1,500.50;
        // Total COS = 590.25 shown in brackets; PBT Wk1 = 400.25 − 100 = 300.25.
        $this->get('/entries/2026/11')->assertOk()
            ->assertSee('400.25')
            ->assertSee('1,500.50')
            ->assertSee('(590.25)')
            ->assertSee('300.25');

        // Saving the same values again changes nothing.
        $this->put('/entries/2026/11', ['cells' => [$rev->id => [1 => ['actual' => '1000.5']]]])
            ->assertSessionHas('status', 'No changes to save.');
    }

    #[Test]
    public function var_compares_total_actual_with_total_forecast_and_amp_is_shown(): void
    {
        $w = $this->world();
        $rev = $this->line('revenue');
        AmpTarget::create(['company_id' => $w['a']->id, 'year' => 2026, 'month' => 10, 'financial_line_id' => $rev->id, 'amount' => '13542.21']);

        $this->actingAs($w['userA'])->put('/entries/2026/10', ['cells' => [
            $rev->id => [1 => ['actual' => '950', 'forecast' => '1000']],
        ]]);

        $this->get('/entries/2026/10')->assertSee('13,542.21')->assertSee('(50.00)')->assertSee('-5.00%');
    }

    #[Test]
    public function validation_rejects_bad_amounts_future_actuals_and_unknown_weeks(): void
    {
        $w = $this->world();
        $rev = $this->line('revenue');
        $this->actingAs($w['userA']);

        $this->put('/entries/2026/11', ['cells' => [$rev->id => [1 => ['actual' => '12abc']]]])
            ->assertSessionHasErrors('cells.'.$rev->id.'.1.actual');

        $this->put('/entries/2026/11', ['cells' => [$rev->id => [4 => ['actual' => '10']]]])
            ->assertSessionHasErrors('cells.'.$rev->id.'.4.actual');

        // Forecasts for future weeks are fine.
        $this->put('/entries/2026/11', ['cells' => [$rev->id => [4 => ['forecast' => '10']]]])->assertSessionHasNoErrors();

        // February 2026 has 4 weeks.
        $this->put('/entries/2026/2', ['cells' => [$rev->id => [5 => ['actual' => '10']]]])
            ->assertSessionHasErrors('cells.'.$rev->id.'.5');

        $this->put('/entries/2026/11', ['cells' => [$rev->id => [1 => ['actual' => '99999999999999']]]])
            ->assertSessionHasErrors();

        $this->assertSame(1, Entry::count());
    }

    #[Test]
    public function derived_lines_cannot_be_written(): void
    {
        $w = $this->world();
        $gp = $this->line('gross_profit');

        $this->actingAs($w['userA'])->put('/entries/2026/11', ['cells' => [$gp->id => [1 => ['actual' => '1']]]])
            ->assertSessionHasErrors("cells.{$gp->id}");
        $this->assertSame(0, Entry::count());
    }

    #[Test]
    public function locked_months_are_read_only(): void
    {
        $w = $this->world();
        $rev = $this->line('revenue');
        Period::create(['company_id' => null, 'year' => 2026, 'month' => 10, 'is_locked' => true]);

        $this->actingAs($w['userA'])->put('/entries/2026/10', ['cells' => [$rev->id => [1 => ['actual' => '5']]]])
            ->assertSessionHasErrors('period');
        $this->get('/entries/2026/10')->assertSee('Locked, read-only')->assertDontSee('Save October 2026');

        // Admin can't write either until it's unlocked.
        $this->actingAs($w['admin'])->put("/group/companies/{$w['a']->id}/entries/2026/10", ['cells' => [$rev->id => [1 => ['actual' => '5']]]])
            ->assertSessionHasErrors('period');

        // A company-specific unlock overrides the group-wide lock.
        Period::create(['company_id' => $w['a']->id, 'year' => 2026, 'month' => 10, 'is_locked' => false]);
        $this->actingAs($w['userA'])->put('/entries/2026/10', ['cells' => [$rev->id => [1 => ['actual' => '5']]]])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Entry::count());
    }

    #[Test]
    public function custom_rows_can_be_added_used_and_removed(): void
    {
        $w = $this->world();
        $this->actingAs($w['userA']);

        $this->post('/lines', ['label' => 'Mould Repairs', 'group' => 'cost_of_sales'])->assertSessionHasNoErrors();
        $line = FinancialLine::where('company_id', $w['a']->id)->firstOrFail();
        $this->assertSame('cost_of_sales', $line->group->value);

        $rev = $this->line('revenue');
        $this->put('/entries/2026/11', ['cells' => [
            $rev->id => [1 => ['actual' => '100']],
            $line->id => [1 => ['actual' => '30']],
        ]])->assertSessionHasNoErrors();

        // Custom line feeds Cost of Sales → GP 70.00
        $this->get('/entries/2026/11')->assertSee('Mould Repairs')->assertSee('70.00');

        // Other companies don't see it.
        $this->actingAs($w['userB'])->get('/entries/2026/11')->assertDontSee('Mould Repairs');

        // Remove: has data, so it's archived rather than deleted.
        $this->actingAs($w['userA'])->delete("/lines/{$line->id}")->assertSessionHas('status');
        $this->assertFalse($line->fresh()->is_active);
        $this->get('/entries/2026/11')->assertDontSee('data-row="'.$line->code.'"', false);
    }

    #[Test]
    public function the_grid_marks_this_week_and_disables_future_actuals(): void
    {
        $w = $this->world();
        $html = $this->actingAs($w['userA'])->get('/entries/2026/11')->assertOk()->getContent();

        $this->assertStringContainsString('Wk3 · this week', $html);
        $rev = $this->line('revenue');
        $this->assertMatchesRegularExpression('/name="cells\['.$rev->id.'\]\[4\]\[actual\]"[^>]*disabled/s', $html);
        $this->assertDoesNotMatchRegularExpression('/name="cells\['.$rev->id.'\]\[3\]\[actual\]"[^>]*disabled/s', $html);
    }
}
