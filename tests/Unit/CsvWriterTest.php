<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CsvWriter;
use App\Support\Exception\ExportException;
use PHPUnit\Framework\TestCase;

/**
 * Logic area 9 — CSV formatting, split out of ReportService by the SRP audit
 * (docs/quality/refactor-log.md).
 */
final class CsvWriterTest extends TestCase
{
    private CsvWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new CsvWriter();
    }

    /**
     * The reason fputcsv is used instead of implode(','): a value containing a
     * comma would shift every following column, and the file would look valid
     * while being wrong.
     *
     * Proven by round-trip — generated, then parsed back — rather than by
     * looking for quotes in the output.
     */
    public function testValuesContainingCommasQuotesAndNewlinesSurviveARoundTrip(): void
    {
        $rows = [
            ['SKU', 'Product'],
            ['SKU-1', 'Kabel HDMI, 2m'],
            ['SKU-2', 'Screen 24"'],
            ['SKU-3', "Two\nLines"],
        ];

        self::assertSame($rows, $this->parse($this->writer->write($rows)));
    }

    public function testAnEmbeddedNewlineDoesNotBecomeAnExtraRow(): void
    {
        $parsed = $this->parse($this->writer->write([
            ['Name'],
            ["First\nSecond"],
        ]));

        self::assertCount(2, $parsed, 'Two rows, not three.');
        self::assertSame("First\nSecond", $parsed[1][0]);
    }

    public function testTheFileStartsWithAByteOrderMarkSoSpreadsheetsReadUtf8(): void
    {
        self::assertStringStartsWith("\xEF\xBB\xBF", $this->writer->write([['Café']]));
    }

    public function testAnEmptyReportStillProducesAValidFile(): void
    {
        $csv = $this->writer->write([['SKU', 'Product']]);

        self::assertSame([['SKU', 'Product']], $this->parse($csv), 'Header only, no data rows.');
    }

    /** @return list<list<string>> */
    private function parse(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        self::assertNotFalse($handle);

        fwrite($handle, str_replace("\xEF\xBB\xBF", '', $csv));
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            /** @var list<string> $row */
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * The export's failure path. fopen on php://temp does not fail in any
     * situation a test can create -- it would take an exhausted file-descriptor
     * limit -- so what is pinned here is the exception's own contract: it names
     * the stream that could not be opened. Without that, the log would say only
     * "the export failed" and give nobody a place to start.
     */
    public function testTheExportFailureNamesTheStreamItCouldNotOpen(): void
    {
        $exception = ExportException::streamUnavailable('php://output');

        self::assertStringContainsString('php://output', $exception->getMessage());
        self::assertStringContainsString('CSV export', $exception->getMessage());
    }
}
