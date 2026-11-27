<?php

namespace App\Services\Excel;

use App\Enums\LineGroup;
use App\Models\Company;
use App\Models\FinancialLine;
use App\Services\Finance\EntryGrid;
use App\Services\Finance\FinancialCalculator;
use App\Support\Money;
use App\Support\Week;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Excel round-trip for the monthly grid.
 *
 * template(): a pre-filled workbook with the company's lines, live Excel formulas
 *             for the derived rows and a hidden _meta sheet identifying company/month.
 * parse():    reads the template back (or any sheet with "Wk1 Act"-style headers and
 *             line labels) into line_id → week → kind → amount.
 */
final class EntryWorkbook
{
    public const META_SHEET = '_meta';

    public const SIGNATURE = 'hesb-entries-v1';

    private const NUMBER_FORMAT = '#,##0.00;(#,##0.00);"-"';

    private const HEADER_ROW = 5;

    private const FIRST_DATA_ROW = 7;

    public function __construct(private EntryGrid $grid) {}

    public function template(Company $company, int $year, int $month): Spreadsheet
    {
        $data = $this->grid->build($company, $year, $month);
        $weeks = $data['weeks'];
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('HESB Digital Center')->setTitle("{$company->name} {$data['monthName']}");
        $sheet = $book->getActiveSheet()->setTitle(substr(CarbonImmutable::create($year, $month)->format('M Y'), 0, 31));

        $sheet->setCellValue('A1', 'HESB Digital Center — Weekly Financial Flash');
        $sheet->setCellValue('A2', "{$company->name} · {$data['monthName']} · Amounts in RM '000");
        $sheet->setCellValue('A3', 'Type in the white cells. Grey rows calculate themselves. Blank cells leave existing values unchanged. Do not edit column A.');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A3')->getFont()->setItalic(true)->getColor()->setRGB('718096');

        // Header rows
        $h = self::HEADER_ROW;
        $sheet->setCellValue("A{$h}", 'Code')->setCellValue("B{$h}", 'Line');
        $col = 3;
        $weekCols = [];
        foreach ($weeks as $w => $info) {
            $a = Coordinate::stringFromColumnIndex($col);
            $f = Coordinate::stringFromColumnIndex($col + 1);
            $sheet->setCellValue("{$a}{$h}", "Wk{$w} ({$info['range']})");
            $sheet->mergeCells("{$a}{$h}:{$f}{$h}");
            $sheet->setCellValue($a.($h + 1), 'Act')->setCellValue($f.($h + 1), 'Fc');
            $weekCols[$w] = ['actual' => $a, 'forecast' => $f];
            $col += 2;
        }
        $totA = Coordinate::stringFromColumnIndex($col);
        $totF = Coordinate::stringFromColumnIndex($col + 1);
        $ampC = Coordinate::stringFromColumnIndex($col + 2);
        $sheet->setCellValue("{$totA}{$h}", 'Total Act')->setCellValue("{$totF}{$h}", 'Total Fc')->setCellValue("{$ampC}{$h}", 'AMP');
        foreach ([$totA, $totF, $ampC] as $c) {
            $sheet->mergeCells("{$c}{$h}:{$c}".($h + 1));
        }
        $last = $ampC;
        $sheet->getStyle("A{$h}:{$last}".($h + 1))->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '313860']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // Data rows
        $rowOf = [];
        $r = self::FIRST_DATA_ROW;
        foreach ($data['rows'] as $row) {
            $rowOf[$row['line']->code] = $r++;
        }
        $groupRows = [];
        foreach ($data['rows'] as $row) {
            $line = $row['line'];
            if ($line->isInput() && $line->group) {
                $groupRows[$line->group->value][] = $rowOf[$line->code];
            }
        }

        $valueCols = [];
        foreach ($weekCols as $pair) {
            $valueCols[] = $pair['actual'];
            $valueCols[] = $pair['forecast'];
        }

        foreach ($data['rows'] as $row) {
            /** @var FinancialLine $line */
            $line = $row['line'];
            $r = $rowOf[$line->code];
            $sheet->setCellValue("A{$r}", $line->code)->setCellValue("B{$r}", $line->label);

            foreach ($weekCols as $w => $pair) {
                foreach ($pair as $kind => $c) {
                    if ($line->isInput()) {
                        $v = $row['weeks'][$w][$kind];
                        if ($v !== null) {
                            $sheet->setCellValueExplicit("{$c}{$r}", (float) $v, DataType::TYPE_NUMERIC);
                        }
                        $editable = ! $data['locked'] && ! ($kind === 'actual' && $weeks[$w]['isFuture']);
                        $sheet->getStyle("{$c}{$r}")->getProtection()->setLocked($editable ? Protection::PROTECTION_UNPROTECTED : Protection::PROTECTION_PROTECTED);
                        if (! $editable) {
                            $sheet->getStyle("{$c}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EDF2F7');
                        }
                    } else {
                        $sheet->setCellValue("{$c}{$r}", $this->derivedFormula($line->code, $c, $rowOf, $groupRows));
                    }
                }
            }

            $actRefs = implode(',', array_map(fn ($p) => $p['actual'].$r, $weekCols));
            $fcRefs = implode(',', array_map(fn ($p) => $p['forecast'].$r, $weekCols));
            $sheet->setCellValue("{$totA}{$r}", "=SUM({$actRefs})");
            $sheet->setCellValue("{$totF}{$r}", "=SUM({$fcRefs})");
            if ($row['amp'] !== null) {
                $sheet->setCellValueExplicit("{$ampC}{$r}", (float) $row['amp'], DataType::TYPE_NUMERIC);
            }

            if (! $line->isInput()) {
                $sheet->getStyle("A{$r}:{$last}{$r}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
                ]);
            }
        }

        $lastRow = max($rowOf ?: [self::FIRST_DATA_ROW]);
        $sheet->getStyle('C'.self::FIRST_DATA_ROW.":{$last}{$lastRow}")->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        $sheet->getStyle("A{$h}:{$last}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E0');
        $sheet->getStyle("{$totA}".self::FIRST_DATA_ROW.":{$last}{$lastRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F7FAFC');

        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('A')->setVisible(true);
        $sheet->getColumnDimension('B')->setWidth(26);
        for ($i = 3; $i <= Coordinate::columnIndexFromString($last); $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(13);
        }
        $sheet->freezePane('C'.self::FIRST_DATA_ROW);
        $sheet->getStyle('A'.self::FIRST_DATA_ROW.":A{$lastRow}")->getFont()->getColor()->setRGB('A0AEC0');
        $sheet->getProtection()->setSheet(true)->setFormatColumns(false)->setFormatRows(false);

        // Hidden identity sheet: stops a file for one company/month being loaded into another.
        $meta = $book->createSheet()->setTitle(self::META_SHEET);
        $meta->fromArray([[self::SIGNATURE], [$company->code], [$year], [$month]]);
        $meta->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * @return array{cells: array<int, array<int, array<string, string>>>, warnings: list<string>, errors: list<string>}
     */
    public function parse(string $path, Company $company, int $year, int $month): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (Throwable) {
            return ['cells' => [], 'warnings' => [], 'errors' => ['The file could not be read. Upload an .xlsx, .xls or .csv file.']];
        }

        $errors = [];
        $warnings = [];

        if ($meta = $book->getSheetByName(self::META_SHEET)) {
            if ((string) $meta->getCell('A1')->getValue() === self::SIGNATURE) {
                $fileCompany = (string) $meta->getCell('A2')->getValue();
                $fileYear = (int) $meta->getCell('A3')->getValue();
                $fileMonth = (int) $meta->getCell('A4')->getValue();
                if ($fileCompany !== $company->code) {
                    return ['cells' => [], 'warnings' => [], 'errors' => ['This template belongs to a different company. Download the template from this page and try again.']];
                }
                if ($fileYear !== $year || $fileMonth !== $month) {
                    $label = CarbonImmutable::create($fileYear ?: $year, $fileMonth ?: 1)->format('F Y');

                    return ['cells' => [], 'warnings' => [], 'errors' => ["This template is for {$label}. Switch to that month first, or download this month's template."]];
                }
            }
        }

        $sheet = null;
        foreach ($book->getWorksheetIterator() as $ws) {
            if ($ws->getTitle() !== self::META_SHEET) {
                $sheet = $ws;
                break;
            }
        }
        if (! $sheet) {
            return ['cells' => [], 'warnings' => [], 'errors' => ['The workbook has no data sheet.']];
        }

        $columns = $this->detectColumns($sheet);
        if (! $columns['map']) {
            return ['cells' => [], 'warnings' => [], 'errors' => ['No week columns found. Headers should look like "Wk1 Act" / "Wk1 Fc" (or "Wk1" with "Act" / "Fc" underneath).']];
        }

        $lines = FinancialLine::forCompany($company->id)->get();
        $byKey = [];
        foreach ($lines as $l) {
            $byKey[$this->key($l->code)] = $l;
            $byKey[$this->key($l->label)] = $l;
        }

        $weekCount = Week::count($year, $month);
        $cells = [];
        $seen = [];
        $highestRow = $sheet->getHighestDataRow();

        for ($r = $columns['dataRow']; $r <= $highestRow; $r++) {
            $line = null;
            $label = null;
            for ($c = 1; $c <= min(3, $columns['firstValueCol'] - 1); $c++) {
                $text = trim((string) $sheet->getCell([$c, $r])->getValue());
                if ($text === '') {
                    continue;
                }
                $label ??= $text;
                if (isset($byKey[$this->key($text)])) {
                    $line = $byKey[$this->key($text)];
                    break;
                }
            }

            $hasValues = false;
            foreach ($columns['map'] as $colIndex => $_) {
                if (trim((string) $sheet->getCell([$colIndex, $r])->getValue()) !== '') {
                    $hasValues = true;
                    break;
                }
            }

            if (! $line) {
                if ($label !== null && $hasValues && ! $this->looksDerived($label)) {
                    $warnings[] = "Row {$r} \"{$label}\" isn't a line for this company and was skipped. Add it with \"+ Add Row\" first.";
                }

                continue;
            }
            if (! $line->isInput()) {
                continue; // derived rows are always recalculated
            }
            if (isset($seen[$line->id])) {
                $warnings[] = "Row {$r} repeats \"{$line->label}\"; only the first occurrence was used.";

                continue;
            }
            $seen[$line->id] = true;

            foreach ($columns['map'] as $colIndex => [$week, $kind]) {
                if ($week > $weekCount) {
                    continue;
                }
                $cell = $sheet->getCell([$colIndex, $r]);
                try {
                    $raw = $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();
                    $amount = Money::parse(is_string($raw) ? trim($raw) : $raw);
                } catch (InvalidArgumentException|Throwable) {
                    $errors[] = "Cell {$cell->getCoordinate()} ({$line->label} Wk{$week} ".ucfirst($kind).') is not a valid amount.';

                    continue;
                }
                if ($amount !== null) {
                    $cells[$line->id][$week][$kind] = $amount;
                }
            }
        }

        if (! $cells && ! $errors) {
            $errors[] = 'No amounts were found in the file.';
        }

        return ['cells' => $cells, 'warnings' => $warnings, 'errors' => $errors];
    }

    /**
     * Find the week/kind of every value column.
     *
     * @return array{map: array<int, array{0:int,1:string}>, dataRow: int, firstValueCol: int}
     */
    private function detectColumns(Worksheet $sheet): array
    {
        $maxCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $maxRow = min(15, $sheet->getHighestDataRow());

        for ($r = 1; $r <= $maxRow; $r++) {
            $map = [];
            $currentWeek = null;
            $usedSubRow = false;
            for ($c = 1; $c <= $maxCol; $c++) {
                $text = strtolower(trim((string) $sheet->getCell([$c, $r])->getValue()));
                if (preg_match('/^w(?:ee)?k\.?\s*([1-5])\b(.*)$/', $text, $m)) {
                    $currentWeek = (int) $m[1];
                    $kind = $this->kindOf($m[2]);
                } elseif ($text !== '') {
                    $currentWeek = null; // e.g. "Total Act" ends the week block

                    continue;
                } else {
                    $kind = null;
                }
                if ($currentWeek === null) {
                    continue;
                }
                $kind ??= $this->kindOf(strtolower((string) $sheet->getCell([$c, $r + 1])->getValue()));
                if ($kind !== null) {
                    if ($text === '' || ! preg_match('/act|fc|fore/', $text)) {
                        $usedSubRow = true;
                    }
                    $map[$c] = [$currentWeek, $kind];
                }
            }
            if ($map) {
                return ['map' => $map, 'dataRow' => $r + ($usedSubRow ? 2 : 1), 'firstValueCol' => min(array_keys($map))];
            }
        }

        return ['map' => [], 'dataRow' => 1, 'firstValueCol' => 1];
    }

    private function kindOf(string $text): ?string
    {
        $text = trim($text);

        return match (true) {
            (bool) preg_match('/\bact(ual)?\b/', $text) => 'actual',
            (bool) preg_match('/\b(fc|fcst|forecast)\b/', $text) => 'forecast',
            default => null,
        };
    }

    private function key(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(str_replace('&', 'and', $s)));
    }

    private function looksDerived(string $label): bool
    {
        return in_array($this->key($label), ['totalrevenue', 'costofsales', 'grossprofit', 'profitbeforetax', 'pbt', 'code', 'line'], true);
    }

    /** Excel formula mirroring FinancialCalculator for one derived cell. */
    private function derivedFormula(string $code, string $col, array $rowOf, array $groupRows): string
    {
        $sum = function (LineGroup $g) use ($col, $groupRows) {
            $rows = $groupRows[$g->value] ?? [];

            return $rows ? 'SUM('.implode(',', array_map(fn ($r) => $col.$r, $rows)).')' : '0';
        };

        return match ($code) {
            FinancialCalculator::TOTAL_REVENUE => '='.$sum(LineGroup::Revenue),
            FinancialCalculator::COST_OF_SALES => '='.$sum(LineGroup::CostOfSales),
            FinancialCalculator::GROSS_PROFIT => "={$col}{$rowOf[FinancialCalculator::TOTAL_REVENUE]}-{$col}{$rowOf[FinancialCalculator::COST_OF_SALES]}",
            FinancialCalculator::PBT => "={$col}{$rowOf[FinancialCalculator::GROSS_PROFIT]}-".$sum(LineGroup::Opex).'+'.$sum(LineGroup::OtherIncome).'-'.$sum(LineGroup::Finance),
            default => '',
        };
    }
}
