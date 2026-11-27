<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MonthlyResult;
use App\Models\Period;
use App\Services\Excel\MonthlyResultsWorkbook;
use App\Services\Finance\PerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The client's results sheet: "HA Rev" / "HA PBT" / "HA EBITDA" blocks, AMP / Act / Var. */
class MonthlyResultsTest extends TestCase
{
    use RefreshDatabase;

    /** Figures from the client's screenshot (RM '000). Nov/Dec have no actuals yet. */
    private const REV = [[11391, 6402], [9109, 6461], [8879, 7545], [12319, 8186], [10156, 6459], [11046, 11641],
        [12385, 12984], [11360, 11442], [10923, 12796], [10637, 14867], [10609, null], [13890, null]];

    private const PBT = [[12, -3487], [-1241, -3210], [-1800, -2254], [1418, -1252], [-657, -3199], [143, 1122],
        [923, 1684], [250, 602], [-53, 2137], [-377, 4411], [-174, null], [3122, null]];

    private const EBITDA = [[1916, -1701], [675, -1320], [260, -393], [2863, 641]];

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 10:00');
        Storage::fake('local');
        $this->w = $this->world();
    }

    private function sheetFile(?callable $tweak = null): UploadedFile
    {
        $book = new Spreadsheet;
        $s = $book->getActiveSheet();
        $blocks = [['B', 'HA Rev', self::REV, true], ['H', 'HA PBT', self::PBT, false], ['N', 'HA EBITDA', self::EBITDA, false]];
        foreach ($blocks as [$col, $title, $rows, $dates]) {
            $c = ord($col);
            $s->setCellValue("{$col}2", $title);
            $s->setCellValue(chr($c + 1).'2', 'AMP')->setCellValue(chr($c + 2).'2', 'Act')->setCellValue(chr($c + 3).'2', 'Var');
            foreach ($rows as $i => [$amp, $act]) {
                $r = 3 + $i;
                // Rev block uses real Excel dates formatted "mmm-yy"; PBT uses text "Jan-26".
                $dates
                    ? $s->setCellValue("{$col}{$r}", ExcelDate::formattedPHPToExcel(2026, $i + 1, 1))
                    : $s->setCellValue("{$col}{$r}", date('M', mktime(0, 0, 0, $i + 1, 1)).'-26');
                $s->setCellValue(chr($c + 1).$r, $amp);
                if ($act !== null) {
                    $s->setCellValue(chr($c + 2).$r, $act);
                    $s->setCellValue(chr($c + 3).$r, '='.chr($c + 2).$r.'-'.chr($c + 1).$r);
                }
            }
            $s->setCellValue("{$col}16", 'YTD Oct-26')->setCellValue(chr($c + 1).'16', 999999)->setCellValue(chr($c + 2).'16', 999999);
            $s->setCellValue("{$col}18", 'YTD Dec-26')->setCellValue(chr($c + 1).'18', 888888);
        }
        $s->setCellValue('C30', 'Revenue'); // a stray label that is not a block
        if ($tweak) {
            $tweak($s);
        }
        $path = tempnam(sys_get_temp_dir(), 'res').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'HA results Oct-26.xlsx', null, null, true);
    }

    #[Test]
    public function the_parser_reads_the_clients_layout(): void
    {
        $file = $this->sheetFile();
        $r = app(MonthlyResultsWorkbook::class)->parse($file->getPathname());

        $this->assertSame([], $r['errors']);
        $this->assertEqualsCanonicalizing(['revenue', 'pbt', 'ebitda'], $r['metrics']);
        $this->assertSame(['amp' => '11391.00', 'actual' => '6402.00'], $r['data'][2026][1]['revenue']);
        $this->assertSame(['amp' => '-1241.00', 'actual' => '-3210.00'], $r['data'][2026][2]['pbt']);
        $this->assertSame(['amp' => '10609.00', 'actual' => null], $r['data'][2026][11]['revenue']);
        $this->assertSame('641.00', $r['data'][2026][4]['ebitda']['actual']);
        $this->assertCount(12, $r['data'][2026]);                        // YTD rows ignored
        $this->assertStringNotContainsString('999999', json_encode($r['data']));
    }

    #[Test]
    public function company_user_uploads_previews_then_confirms_and_dashboards_use_the_figures(): void
    {
        $this->actingAs($this->w['userA']);

        $this->post('/results/upload', ['file' => $this->sheetFile()])->assertRedirect('/results/2026');
        $this->assertSame(0, MonthlyResult::count()); // preview only

        $this->get('/results/2026')->assertOk()
            ->assertSee('Check before saving')->assertSee('Confirm &amp; save', false)->assertSee('11,391.00');

        $this->post('/results/confirm')->assertRedirect('/results/2026')->assertSessionHas('status');
        // 12 rev amp + 10 rev act + 12 pbt amp + 10 pbt act + 4 + 4 ebitda
        $this->assertSame(52, MonthlyResult::where('company_id', $this->w['a']->id)->get()->sum(fn ($r) => ($r->amp !== null) + ($r->actual !== null)));
        $this->assertSame(0, MonthlyResult::where('company_id', $this->w['b']->id)->count());
        $this->assertSame(52, AuditLog::where('action', 'results.upload')->count());

        // YTD Oct-26 like the sheet: Rev AMP Jan-Oct 108,205 vs Act 98,783.
        $this->get('/results/2026')->assertSee('YTD Oct-26')->assertSee('98,783.00')->assertSee('108,205.00');

        // Dashboards now run on the reported figures.
        $k = app(PerformanceService::class)->dashboard(collect([$this->w['a']]), 2026)['kpis'];
        $this->assertSame('98783.00', $k['total_revenue']['actual']);
        $this->assertSame('-3446.00', $k['pbt']['actual']); // sum of Jan-Oct actuals
        $this->assertNull($k['pbt']['pct']); // plan to date is a loss → no "% of AMP"
        $this->get('/dashboard')->assertOk()->assertSee('98,783.00')->assertSee('vs AMP (plan is a loss)');
    }

    #[Test]
    public function blank_cells_keep_saved_values_and_locked_months_skip_actuals(): void
    {
        $this->actingAs($this->w['userA']);
        $this->post('/results/upload', ['file' => $this->sheetFile()]);
        $this->post('/results/confirm');

        Period::create(['company_id' => null, 'year' => 2026, 'month' => 1, 'is_locked' => true]);
        $file = $this->sheetFile(function ($s) {
            $s->setCellValue('D3', 7000);   // Jan Rev Act (locked month)
            $s->setCellValue('C4', 9500);   // Feb Rev AMP (AMP isn't period-locked)
            $s->setCellValue('D5', null);   // Mar Rev Act blank
        });
        $this->post('/results/upload', ['file' => $file]);
        $this->get('/results/2026')->assertSee('will be skipped');
        $this->post('/results/confirm');

        $rev = fn ($m) => MonthlyResult::where(['company_id' => $this->w['a']->id, 'month' => $m, 'metric' => 'revenue'])->first();
        $this->assertSame('6402.00', $rev(1)->actual);  // locked: unchanged
        $this->assertSame('9500.00', $rev(2)->amp);     // updated
        $this->assertSame('7545.00', $rev(3)->actual);  // blank: unchanged
    }

    #[Test]
    public function access_rules(): void
    {
        $b = $this->w['b']->id;

        // Company user can't upload for another company, nor see its figures.
        $this->actingAs($this->w['userA'])->post("/group/companies/{$b}/results/upload", ['file' => $this->sheetFile()])->assertForbidden();
        $this->get("/group/companies/{$b}/results/2026")->assertForbidden();

        // CEO: read yes, write no.
        $this->actingAs($this->w['ceo'])->get("/group/companies/{$b}/results/2026")->assertOk()->assertSee('View only')->assertDontSee('Upload results sheet');
        $this->post("/group/companies/{$b}/results/upload", ['file' => $this->sheetFile()])->assertForbidden();
        $this->post("/group/companies/{$b}/results/confirm")->assertForbidden();

        // Admin uploads for B.
        $this->actingAs($this->w['admin'])->post("/group/companies/{$b}/results/upload", ['file' => $this->sheetFile()])
            ->assertRedirect("/group/companies/{$b}/results/2026");
        $this->post("/group/companies/{$b}/results/confirm");
        $this->assertSame('6402.00', MonthlyResult::where(['company_id' => $b, 'month' => 1, 'metric' => 'revenue'])->value('actual'));

        // A preview made for B can't be confirmed into A.
        $this->post("/group/companies/{$b}/results/upload", ['file' => $this->sheetFile()]);
        $this->post("/group/companies/{$this->w['a']->id}/results/confirm")->assertStatus(422);
    }

    #[Test]
    public function files_without_result_blocks_are_rejected(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['Name', 'Value'], ['x', 1]]);
        $path = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->actingAs($this->w['userA'])
            ->post('/results/upload', ['file' => new UploadedFile($path, 'bad.xlsx', null, null, true)])
            ->assertSessionHasErrors('file');
        $this->assertNull(session('results_preview'));
    }
}
