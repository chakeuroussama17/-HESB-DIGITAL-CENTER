<?php

namespace Tests\Feature;

use App\Models\AmpTarget;
use App\Models\AuditLog;
use App\Models\Entry;
use App\Models\FinancialLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 10:00');
        $this->w = $this->world();
        $rev = FinancialLine::standard()->where('code', 'revenue')->value('id');
        $dm = FinancialLine::standard()->where('code', 'direct_materials')->value('id');
        foreach ([[$this->w['a'], '1000.50'], [$this->w['b'], '400.00']] as [$c, $v]) {
            Entry::create(['company_id' => $c->id, 'year' => 2026, 'month' => 1, 'week' => 1, 'financial_line_id' => $rev, 'actual' => $v, 'forecast' => '900.00']);
            AmpTarget::create(['company_id' => $c->id, 'year' => 2026, 'month' => 1, 'financial_line_id' => $rev, 'amount' => '1000.00']);
        }
        Entry::create(['company_id' => $this->w['a']->id, 'year' => 2026, 'month' => 1, 'week' => 1, 'financial_line_id' => $dm, 'actual' => '600.25']);
        $this->w['a']->update(['name' => '=HYPERLINK("evil")']); // formula-injection probe
    }

    #[Test]
    public function only_admin_can_export(): void
    {
        foreach (['ceo', 'userA'] as $who) {
            $this->actingAs($this->w[$who])->get('/admin/exports')->assertForbidden();
            $this->get('/admin/exports/summary?year=2026&format=csv')->assertForbidden();
        }
        $this->actingAs($this->w['admin'])->get('/admin/exports')->assertOk()->assertSee('Company P&amp;L', false);
    }

    #[Test]
    public function weekly_entries_export_as_csv_with_injection_guard(): void
    {
        $res = $this->actingAs($this->w['admin'])->get('/admin/exports/entries?year=2026&format=csv')->assertOk();
        $csv = $res->streamedContent();

        $this->assertStringContainsString('1000.50', $csv);
        $this->assertStringContainsString('600.25', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);   // neutralised
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertSame(1, AuditLog::where('action', 'export.entries')->count());
    }

    #[Test]
    public function summary_exports_xlsx_with_group_total(): void
    {
        $res = $this->actingAs($this->w['admin'])->get('/admin/exports/summary?year=2026&format=xlsx')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $sheet = IOFactory::load($path)->getSheet(0);

        $rows = $sheet->toArray(null, false, false);
        $group = collect($rows)->first(fn ($r) => $r[0] === 'HESB Group');
        $this->assertEqualsWithDelta(1400.5, (float) $group[1], 0.001);
        // Text cell with a leading "=" is stored as a string, never a formula.
        $this->assertSame('=HYPERLINK("evil")', $sheet->getCell('A4')->getValue());
        $this->assertFalse($sheet->getCell('A4')->isFormula());
    }

    #[Test]
    public function company_pnl_exports_as_pdf_and_requires_a_company(): void
    {
        $this->actingAs($this->w['admin'])->get('/admin/exports/pnl?year=2026&format=pdf')->assertSessionHasErrors('company');

        $res = $this->get("/admin/exports/pnl?year=2026&format=pdf&company={$this->w['b']->id}")->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    #[Test]
    public function pnl_xlsx_has_derived_rows_and_fy_totals(): void
    {
        $res = $this->actingAs($this->w['admin'])->get("/admin/exports/pnl?year=2026&format=csv&company={$this->w['a']->id}")->assertOk();
        $lines = array_map('str_getcsv', explode("\n", trim(substr($res->streamedContent(), 3))));
        $gp = collect($lines)->first(fn ($r) => $r[0] === 'Gross Profit');
        $this->assertSame('400.25', $gp[1]);   // Jan
        $this->assertSame('400.25', $gp[13]);  // YTD actual
        $rev = collect($lines)->first(fn ($r) => $r[0] === 'Revenue');
        $this->assertSame('1000.00', $rev[14]); // AMP to date (January is finished)
        $this->assertSame('0.50', $rev[15]);    // Var vs AMP to date
        $this->assertSame('1000.00', $rev[16]); // FY AMP
    }
}
