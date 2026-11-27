<?php

namespace App\Services\Excel;

use App\Models\Company;
use App\Models\FinancialLine;
use App\Services\Finance\AmpService;
use App\Support\Money;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/** Excel / CSV round-trip for AMP: lines down, Jan..Dec across. */
final class AmpWorkbook
{
    public const SIGNATURE = 'hesb-amp-v1';

    private const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

    public function __construct(private AmpService $amp) {}

    public function template(Company $company, int $year): Spreadsheet
    {
        $grid = $this->amp->grid($company, $year);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle("AMP {$year}");
        $sheet->setCellValue('A1', "Annual Management Plan {$year} · {$company->name} · RM '000");
        $sheet->setCellValue('A2', 'Monthly targets per line. Costs as positive numbers. Grey rows are calculated on upload.');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $header = ['Code', 'Line', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'FY Total'];
        $sheet->fromArray($header, null, 'A4');
        $sheet->getStyle('A4:O4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '313860']],
        ]);

        $r = 5;
        foreach ($grid['rows'] as $row) {
            $line = $row['line'];
            $sheet->setCellValue("A{$r}", $line->code)->setCellValue("B{$r}", $line->label);
            foreach ($row['months'] as $m => $cell) {
                if ($cell['value'] !== null) {
                    $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex(2 + $m).$r, (float) $cell['value'], DataType::TYPE_NUMERIC);
                }
            }
            $sheet->setCellValue("O{$r}", "=SUM(C{$r}:N{$r})");
            if (! $line->isInput()) {
                $sheet->getStyle("A{$r}:O{$r}")->applyFromArray([
                    'font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
                ]);
            }
            $r++;
        }
        $sheet->getStyle("C5:O{$r}")->getNumberFormat()->setFormatCode('#,##0.00;(#,##0.00);"-"');
        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(26);
        foreach (range('C', 'O') as $c) {
            $sheet->getColumnDimension($c)->setWidth(12);
        }
        $sheet->freezePane('C5');

        $meta = $book->createSheet()->setTitle('_meta');
        $meta->fromArray([[self::SIGNATURE], [$company->code], [$year]]);
        $meta->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /** @return array{cells: array<int, array<int, string>>, warnings: list<string>, errors: list<string>} */
    public function parse(string $path, Company $company, int $year): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (Throwable) {
            return ['cells' => [], 'warnings' => [], 'errors' => ['The file could not be read. Upload an .xlsx, .xls or .csv file.']];
        }

        if (($meta = $book->getSheetByName('_meta')) && $meta->getCell('A1')->getValue() === self::SIGNATURE) {
            if ((string) $meta->getCell('A2')->getValue() !== $company->code || (int) $meta->getCell('A3')->getValue() !== $year) {
                return ['cells' => [], 'warnings' => [], 'errors' => ["This AMP template is for {$meta->getCell('A2')->getValue()} {$meta->getCell('A3')->getValue()}, not {$company->code} {$year}."]];
            }
        }
        $sheet = $book->getSheet(0);

        // Header row: the first row with at least 6 month names.
        $map = [];
        $headerRow = null;
        $maxCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($r = 1; $r <= min(15, $sheet->getHighestDataRow()) && ! $headerRow; $r++) {
            $found = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $t = strtolower(substr(trim((string) $sheet->getCell([$c, $r])->getValue()), 0, 3));
                if (($i = array_search($t, self::MONTHS, true)) !== false) {
                    $found[$c] = $i + 1;
                }
            }
            if (count($found) >= 6) {
                $map = $found;
                $headerRow = $r;
            }
        }
        if (! $headerRow) {
            return ['cells' => [], 'warnings' => [], 'errors' => ['No month columns found. The header row needs Jan, Feb, … Dec.']];
        }

        $lines = FinancialLine::forCompany($company->id)->get();
        $key = fn ($s) => preg_replace('/[^a-z0-9]/', '', strtolower(str_replace('&', 'and', $s)));
        $byKey = [];
        foreach ($lines as $l) {
            $byKey[$key($l->code)] = $l;
            $byKey[$key($l->label)] = $l;
        }

        $cells = [];
        $warnings = [];
        $errors = [];
        $firstVal = min(array_keys($map));
        for ($r = $headerRow + 1; $r <= $sheet->getHighestDataRow(); $r++) {
            $line = null;
            $label = null;
            for ($c = 1; $c < $firstVal; $c++) {
                $t = trim((string) $sheet->getCell([$c, $r])->getValue());
                if ($t === '') {
                    continue;
                }
                $label ??= $t;
                if ($line = $byKey[$key($t)] ?? null) {
                    break;
                }
            }
            if (! $line) {
                if ($label && ! in_array($key($label), ['totalrevenue', 'costofsales', 'grossprofit', 'profitbeforetax'], true)) {
                    $warnings[] = "Row {$r} \"{$label}\" isn't a line for this company and was skipped.";
                }

                continue;
            }
            if (! $line->isInput()) {
                continue;
            }
            foreach ($map as $c => $m) {
                $cell = $sheet->getCell([$c, $r]);
                try {
                    $v = Money::parse($cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue());
                } catch (InvalidArgumentException|Throwable) {
                    $errors[] = "Cell {$cell->getCoordinate()} ({$line->label} ".ucfirst(self::MONTHS[$m - 1]).') is not a valid amount.';

                    continue;
                }
                if ($v !== null) {
                    $cells[$line->id][$m] = $v;
                }
            }
        }
        if (! $cells && ! $errors) {
            $errors[] = 'No amounts were found in the file.';
        }

        return compact('cells', 'warnings', 'errors');
    }
}
