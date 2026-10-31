<?php

declare(strict_types=1);

namespace Cronos\Reports\Infrastructure\Exports;

use Cronos\Reports\Domain\Ports\ExportWriter;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Streams rows straight to disk: memory stays flat whether the sheet has ten rows or two hundred thousand. */
final class OpenSpoutExportWriter implements ExportWriter
{
    public function write(string $path, string $sheet, array $headers, iterable $rows, int $maxRows): array
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(mb_substr($sheet, 0, 31));
        $bold = (new Style)->withFontBold(true);
        $writer->addRow(new Row(array_map(static fn (string $h) => Cell::fromValue($h, $bold), $headers)));

        $count = 0;
        $truncated = false;
        foreach ($rows as $row) {
            if ($count >= $maxRows) {
                $truncated = true;
                break;
            }
            $writer->addRow(Row::fromValues(array_map(self::safe(...), $row)));
            $count++;
        }
        $writer->close();

        return ['rows' => $count, 'truncated' => $truncated];
    }

    /**
     * Text that starts like a formula (= + - @) is neutralised: a customer who typed `=HYPERLINK(...)` as their name must not be able to run
     * anything on the machine of whoever opens the export. Numbers pass through untouched.
     */
    private static function safe(string|int|float|null $value): string|int|float|null
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)) {
            return "'".$value;
        }

        return $value;
    }
}
