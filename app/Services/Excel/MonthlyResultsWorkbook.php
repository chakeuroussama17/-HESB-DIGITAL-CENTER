<?php

namespace App\Services\Excel;

use App\Support\Money;
use DateTimeInterface;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads a company's monthly results sheet: blocks titled e.g. "HA Rev", "HA PBT",
 * "HA EBITDA", each with an AMP and an Act column and one row per month
 * ("Jan-26" … "Dec-26"). YTD and Var rows/columns are ignored: the portal
 * recomputes those. Figures are taken as reported, never recalculated.
 */
final class MonthlyResultsWorkbook
{
    private const MAX_ROWS = 300;

    private const MAX_COLS = 80;

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /**
     * @return array{data: array<int, array<int, array<string, array{amp: ?string, actual: ?string}>>>,
     *               metrics: list<string>, warnings: list<string>, errors: list<string>}
     */
    public function parse(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (Throwable) {
            return $this->fail('The file could not be read. Upload an .xlsx, .xls or .csv file.');
        }

        $data = [];
        $metrics = [];
        $warnings = [];
        $errors = [];

        foreach ($book->getWorksheetIterator() as $sheet) {
            $maxRow = min(self::MAX_ROWS, $sheet->getHighestDataRow());
            $maxCol = min(self::MAX_COLS, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));

            for ($r = 1; $r <= $maxRow; $r++) {
                for ($c = 1; $c <= $maxCol; $c++) {
                    $metric = $this->metricOf($sheet->getCell([$c, $r])->getValue());
                    if ($metric === null) {
                        continue;
                    }
                    $cols = $this->headerColumns($sheet, $r, $c, $maxCol);
                    if ($cols === null) {
                        continue; // a word like "Revenue" that isn't a block title
                    }
                    if (isset($metrics[$metric])) {
                        $warnings[] = "A second \"{$sheet->getCell([$c, $r])->getValue()}\" block on {$sheet->getTitle()} was ignored.";

                        continue;
                    }
                    $metrics[$metric] = true;
                    $this->readBlock($sheet, $r, $c, $cols, $metric, $maxRow, $data, $errors);
                }
            }
        }

        if (! $metrics) {
            return $this->fail('No results blocks found. Each block needs a title such as "HA Rev" or "HA PBT" with "AMP" and "Act" headers next to it, and months (Jan-26 …) below.');
        }
        if (! $data && ! $errors) {
            $errors[] = 'The blocks were found but contain no monthly figures.';
        }
        if (! isset($metrics['revenue'])) {
            $warnings[] = 'No Revenue block found.';
        }

        ksort($data);

        return ['data' => $data, 'metrics' => array_keys($metrics), 'warnings' => $warnings, 'errors' => $errors];
    }

    private function readBlock(Worksheet $sheet, int $headerRow, int $labelCol, array $cols, string $metric, int $maxRow, array &$data, array &$errors): void
    {
        $blank = 0;
        for ($r = $headerRow + 1; $r <= $maxRow && $blank < 3; $r++) {
            $label = $sheet->getCell([$labelCol, $r])->getValue();
            if ($label === null || trim((string) $label) === '') {
                $blank++;

                continue;
            }
            $blank = 0;
            if ($this->metricOf($label) !== null) {
                break; // next block starts directly below
            }
            $period = $this->period($label);
            if ($period === null) {
                continue; // YTD rows, notes, totals
            }
            [$year, $month] = $period;

            foreach (['amp' => $cols['amp'], 'actual' => $cols['actual']] as $kind => $col) {
                if ($col === null) {
                    continue;
                }
                $cell = $sheet->getCell([$col, $r]);
                try {
                    $raw = $cell->isFormula() ? ($cell->getOldCalculatedValue() ?? $cell->getCalculatedValue()) : $cell->getValue();
                    $value = Money::parse(is_string($raw) ? trim($raw) : $raw);
                } catch (InvalidArgumentException|Throwable) {
                    $errors[] = "Cell {$sheet->getTitle()}!{$cell->getCoordinate()} is not a valid amount.";

                    continue;
                }
                $data[$year][$month][$metric][$kind] = $value;
            }
            $data[$year][$month][$metric] = ($data[$year][$month][$metric] ?? []) + ['amp' => null, 'actual' => null];
        }
    }

    /** @return array{amp: ?int, actual: ?int}|null */
    private function headerColumns(Worksheet $sheet, int $row, int $titleCol, int $maxCol): ?array
    {
        $amp = $actual = null;
        for ($c = $titleCol + 1; $c <= min($maxCol, $titleCol + 6); $c++) {
            $t = strtolower(trim((string) $sheet->getCell([$c, $row])->getValue()));
            if ($t === 'amp' && $amp === null) {
                $amp = $c;
            } elseif (in_array($t, ['act', 'actual', 'actuals'], true) && $actual === null) {
                $actual = $c;
            } elseif ($t !== '' && $this->metricOf($t) !== null) {
                break; // ran into the next block
            }
        }

        return $amp === null && $actual === null ? null : ['amp' => $amp, 'actual' => $actual];
    }

    private function metricOf(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $t = strtolower(trim(preg_replace('/\s+/', ' ', $value)));
        // optional company prefix ("HA", "HDC"…), then the metric name
        if (! preg_match('/^(?:[a-z0-9&.\-]{1,12} )?(rev|revenue|gp|gross profit|pbt|pbt ?\/ ?\(?lbt\)?|lbt|ebitda)$/', $t, $m)) {
            return null;
        }

        return match (true) {
            str_starts_with($m[1], 'rev') => 'revenue',
            $m[1] === 'gp', $m[1] === 'gross profit' => 'gross_profit',
            $m[1] === 'ebitda' => 'ebitda',
            default => 'pbt',
        };
    }

    /** "Jan-26", "Jan 2026", "January 2026", Excel date serial or DateTime → [year, month] */
    private function period(mixed $label): ?array
    {
        if ($label instanceof DateTimeInterface) {
            return [(int) $label->format('Y'), (int) $label->format('n')];
        }
        if (is_numeric($label) && $label > 20000 && $label < 80000) {
            $d = ExcelDate::excelToDateTimeObject((float) $label);

            return [(int) $d->format('Y'), (int) $d->format('n')];
        }
        if (is_string($label) && preg_match('/^([a-z]{3})[a-z]*[\s\-\/\'.]*(\d{2}|\d{4})$/i', trim($label), $m)) {
            $month = self::MONTHS[strtolower($m[1])] ?? null;
            if ($month === null) {
                return null;
            }
            $year = (int) $m[2];

            return [$year < 100 ? 2000 + $year : $year, $month];
        }

        return null;
    }

    private function fail(string $message): array
    {
        return ['data' => [], 'metrics' => [], 'warnings' => [], 'errors' => [$message]];
    }
}
