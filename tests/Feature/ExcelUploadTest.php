<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\FinancialLine;
use App\Models\Upload;
use App\Services\Excel\EntryWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExcelUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 10:00');
        Storage::fake('local');
    }

    /** Download the template as the user, fill some cells, return an UploadedFile. */
    private function filledTemplate(array $w, callable $fill): UploadedFile
    {
        $book = app(EntryWorkbook::class)->template($w['a'], 2026, 11);
        $sheet = $book->getSheet(0);
        $fill($sheet);
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'alpha-nov.xlsx', null, null, true);
    }

    private function rowOf($sheet, string $code): int
    {
        for ($r = 1; $r <= 40; $r++) {
            if ($sheet->getCell("A{$r}")->getValue() === $code) {
                return $r;
            }
        }
        $this->fail("row {$code} not found");
    }

    #[Test]
    public function the_template_downloads_prefilled_with_formulas_for_derived_rows(): void
    {
        $w = $this->world();
        $rev = FinancialLine::standard()->where('code', 'revenue')->first();
        Entry::create(['company_id' => $w['a']->id, 'year' => 2026, 'month' => 11, 'week' => 1, 'financial_line_id' => $rev->id, 'actual' => '1234.50']);

        $response = $this->actingAs($w['userA'])->get('/entries/2026/11/template')->assertOk();
        $this->assertStringContainsString('ALPHA_2026-11_weekly.xlsx', $response->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'dl');
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getSheet(0);

        $this->assertSame(1234.5, (float) $sheet->getCell('C'.$this->rowOf($sheet, 'revenue'))->getValue());
        $gp = $this->rowOf($sheet, 'gross_profit');
        $this->assertStringStartsWith('=', (string) $sheet->getCell("C{$gp}")->getValue());
    }

    #[Test]
    public function uploading_a_filled_template_stages_values_into_the_grid_then_save_persists_them(): void
    {
        $w = $this->world();
        $file = $this->filledTemplate($w, function ($s) {
            $s->setCellValue('C'.$this->rowOf($s, 'revenue'), 2000);        // Wk1 Act
            $s->setCellValue('D'.$this->rowOf($s, 'revenue'), '1,900.00');  // Wk1 Fc
            $s->setCellValue('C'.$this->rowOf($s, 'direct_materials'), 1200.255);
        });

        $this->actingAs($w['userA'])->post('/entries/2026/11/upload', ['file' => $file])
            ->assertRedirect('/entries/2026/11')
            ->assertSessionHas('status', fn ($s) => str_contains($s, '3 cells filled'));

        // Not saved yet …
        $this->assertSame(0, Entry::count());
        $this->assertSame(1, Upload::count());

        // … but the grid shows them highlighted, with derived rows recalculated.
        $this->get('/entries/2026/11')
            ->assertSee('3 cells from upload, not saved yet')
            ->assertSee('2,000.00')
            ->assertSee('799.74'); // GP = 2000 − 1200.26
    }

    #[Test]
    public function a_template_from_another_company_or_month_is_rejected(): void
    {
        $w = $this->world();
        $file = $this->filledTemplate($w, fn ($s) => $s->setCellValue('C'.$this->rowOf($s, 'revenue'), 5));

        $this->actingAs($w['userB'])->post('/entries/2026/11/upload', ['file' => $file])
            ->assertSessionHasErrors(['file' => 'This template belongs to a different company. Download the template from this page and try again.']);

        $file2 = $this->filledTemplate($w, fn ($s) => $s->setCellValue('C'.$this->rowOf($s, 'revenue'), 5));
        $this->actingAs($w['userA'])->post('/entries/2026/10/upload', ['file' => $file2])
            ->assertSessionHasErrors('file');
    }

    #[Test]
    public function a_plain_csv_with_labels_and_week_headers_is_understood(): void
    {
        $w = $this->world();
        $csv = "Line,Wk1 Act,Wk1 Fc,Wk2 Act,Wk2 Fc\n"
            ."Revenue,\"1,500.00\",1400,800,\n"
            ."Direct Materials,(20),,,\n"
            ."Gross Profit,999,999,999,999\n"
            ."Mystery Line,5,5,5,5\n";
        $path = tempnam(sys_get_temp_dir(), 'csv').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($w['userA'])->post('/entries/2026/11/upload', ['file' => new UploadedFile($path, 'flash.csv', 'text/csv', null, true)])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '4 cells filled'))
            ->assertSessionHas('upload_warnings', fn ($w) => count($w) === 1 && str_contains($w[0], 'Mystery Line'));
    }

    #[Test]
    public function bad_cells_are_reported_with_their_coordinates(): void
    {
        $w = $this->world();
        $file = $this->filledTemplate($w, fn ($s) => $s->setCellValue('C'.$this->rowOf($s, 'revenue'), 'twelve'));

        $this->actingAs($w['userA'])->post('/entries/2026/11/upload', ['file' => $file])
            ->assertSessionHasErrors('file');
        $this->assertStringContainsString('is not a valid amount', session('errors')->first('file'));
    }

    #[Test]
    public function non_spreadsheet_files_are_refused(): void
    {
        $w = $this->world();
        $this->actingAs($w['userA'])
            ->post('/entries/2026/11/upload', ['file' => UploadedFile::fake()->create('evil.php', 1, 'application/x-php')])
            ->assertSessionHasErrors('file');
    }
}
