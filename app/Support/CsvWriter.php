<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Turns rows into CSV text. Nothing else.
 *
 * Extracted from ReportService, which had grown five responsibilities:
 * authorization, date-range validation, fetching, row assembly, and file
 * formatting (see docs/quality/refactor-log.md, SRP audit). CSV is a file
 * format, not a reporting rule — and the split means ReportService now returns
 * plain tabular data that could equally be rendered as an HTML table or JSON
 * without touching it.
 *
 * Built on fputcsv rather than joining with commas: a value containing a comma,
 * a quote or a newline would otherwise shift every following column, and the
 * file would look valid while being wrong.
 */
final class CsvWriter
{
    /** @param list<list<string>> $rows the first row is normally the header */
    public function write(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Could not open a buffer for the CSV export.');
        }

        // Byte-order mark so Excel reads UTF-8 correctly; without it, accented
        // names appear mangled and the file looks broken to whoever asked for it.
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
