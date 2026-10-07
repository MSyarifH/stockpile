<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Exception\ExportException;

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
            throw ExportException::streamUnavailable('php://temp');
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

    /**
     * Writes rows directly to php://output, bypassing the in-memory buffer.
     *
     * Used by the streaming CSV export so the response body is never held as a
     * single multi-megabyte string: each row is flushed as soon as it is
     * formatted, keeping peak memory proportional to a single row regardless of
     * how many rows the report contains.
     *
     * @param list<list<string>> $rows the first row is normally the header
     */
    public function writeToOutput(array $rows): void
    {
        $handle = fopen('php://output', 'w');
        if ($handle === false) {
            throw ExportException::streamUnavailable('php://output');
        }

        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }
}
