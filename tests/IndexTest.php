<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private string $icsWithEvents = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//Test//EN\r\nBEGIN:VEVENT\r\nUID:abc123@test\r\nSUMMARY:Test Event\r\nDTSTART:20240101T100000Z\r\nDTEND:20240101T110000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    private string $icsEmpty      = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//Test//EN\r\nEND:VCALENDAR\r\n";
    private string $timezoneBlock = "BEGIN:VTIMEZONE\r\nTZID:UTC\r\nEND:VTIMEZONE\r\n";

    // -------------------------------------------------------------------------
    // validateUrl
    // -------------------------------------------------------------------------

    public function testValidateUrl_acceptsValidHttpsUrl(): void
    {
        $this->expectNotToPerformAssertions();
        validateUrl('https://example.com/calendar.ics');
    }

    public function testValidateUrl_rejectsPlainHttp(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Only HTTPS URLs are allowed.');
        validateUrl('http://example.com/calendar.ics');
    }

    public function testValidateUrl_rejectsFtpScheme(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Only HTTPS URLs are allowed.');
        validateUrl('ftp://example.com/calendar.ics');
    }

    public function testValidateUrl_rejectsNonUrl(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid URL.');
        validateUrl('not-a-url-at-all');
    }

    public function testValidateUrl_rejectsEmptyString(): void
    {
        $this->expectException(Exception::class);
        validateUrl('');
    }

    // -------------------------------------------------------------------------
    // insertMissingTimezones
    // -------------------------------------------------------------------------

    public function testInsertMissingTimezones_insertsBeforeFirstVevent(): void
    {
        $result = insertMissingTimezones($this->icsWithEvents, $this->timezoneBlock);

        $posTimezone = strpos($result, 'BEGIN:VTIMEZONE');
        $posEvent    = strpos($result, 'BEGIN:VEVENT');

        $this->assertNotFalse($posTimezone, 'Timezone block should be present');
        $this->assertLessThan($posEvent, $posTimezone, 'Timezone block should appear before VEVENT');
    }

    public function testInsertMissingTimezones_preservesExistingContent(): void
    {
        $result = insertMissingTimezones($this->icsWithEvents, $this->timezoneBlock);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $result);
        $this->assertStringContainsString('SUMMARY:Test Event', $result);
        $this->assertStringContainsString('END:VCALENDAR', $result);
    }

    public function testInsertMissingTimezones_emptyCalendarInsertsBeforeEndVcalendar(): void
    {
        $result = insertMissingTimezones($this->icsEmpty, $this->timezoneBlock);

        $this->assertStringContainsString('BEGIN:VTIMEZONE', $result);
        $posTimezone    = strpos($result, 'BEGIN:VTIMEZONE');
        $posEndCalendar = strpos($result, 'END:VCALENDAR');
        $this->assertLessThan($posEndCalendar, $posTimezone, 'Timezone block should appear before END:VCALENDAR');
    }

    public function testInsertMissingTimezones_emptyCalendarProducesValidStructure(): void
    {
        $result = insertMissingTimezones($this->icsEmpty, $this->timezoneBlock);

        $this->assertStringContainsString('BEGIN:VCALENDAR', $result);
        $this->assertStringContainsString('END:VCALENDAR', $result);
        $this->assertStringNotContainsString('BEGIN:VEVENT', $result);
    }

    public function testInsertMissingTimezones_throwsOnInvalidIcs(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('END:VCALENDAR not found');
        insertMissingTimezones('this is not a valid ics file', $this->timezoneBlock);
    }

    public function testInsertMissingTimezones_multipleEventsInsertsBeforeFirst(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nSUMMARY:First\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nSUMMARY:Second\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $result = insertMissingTimezones($ics, $this->timezoneBlock);

        $posTimezone  = strpos($result, 'BEGIN:VTIMEZONE');
        $posFirstEvent = strpos($result, 'BEGIN:VEVENT');
        $this->assertLessThan($posFirstEvent, $posTimezone);
    }

    // -------------------------------------------------------------------------
    // readMissingTimezones
    // -------------------------------------------------------------------------

    public function testReadMissingTimezones_returnsFileContents(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ics_test_');
        file_put_contents($tmp, $this->timezoneBlock);

        $result = readMissingTimezones($tmp);
        unlink($tmp);

        $this->assertSame($this->timezoneBlock, $result);
    }

    public function testReadMissingTimezones_throwsWhenFileMissing(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing timezones file not found.');
        readMissingTimezones('/nonexistent/path/timezones.txt');
    }

    // -------------------------------------------------------------------------
    // icsHeaders
    // -------------------------------------------------------------------------

    public function testIcsHeaders_includesContentType(): void
    {
        $this->assertContains('Content-Type: text/calendar; charset=utf-8', icsHeaders());
    }

    public function testIcsHeaders_includesCacheControl(): void
    {
        $this->assertContains('Cache-Control: no-cache, must-revalidate', icsHeaders());
    }

    public function testIcsHeaders_includesContentDisposition(): void
    {
        $this->assertContains('Content-Disposition: attachment; filename="modified_calendar.ics"', icsHeaders());
    }

    // -------------------------------------------------------------------------
    // normalizeTimezoneIds
    // -------------------------------------------------------------------------

    public function testNormalizeTimezoneIds_stripsQuotesFromTzid(): void
    {
        $ics = "DTSTART;TZID=\"W. Europe Standard Time\":20260617T110000";
        $result = normalizeTimezoneIds($ics);
        $this->assertStringContainsString('TZID=W. Europe Standard Time', $result);
        $this->assertStringNotContainsString('TZID="', $result);
    }

    public function testNormalizeTimezoneIds_mapsDisplayNameToCanonical(): void
    {
        $ics = 'DTSTART;TZID="(UTC+01:00) Brussels, Copenhagen, Madrid, Paris":20260617T110000';
        $result = normalizeTimezoneIds($ics);
        $this->assertStringContainsString('TZID=Romance Standard Time', $result);
        $this->assertStringNotContainsString('(UTC+01:00) Brussels', $result);
    }

    public function testNormalizeTimezoneIds_preservesUnknownTzid(): void
    {
        $ics = 'DTSTART;TZID="Some Unknown Timezone":20260617T110000';
        $result = normalizeTimezoneIds($ics);
        $this->assertStringContainsString('TZID=Some Unknown Timezone', $result);
        $this->assertStringNotContainsString('TZID="', $result);
    }

    public function testNormalizeTimezoneIds_handlesMultipleProperties(): void
    {
        $ics = "DTSTART;TZID=\"(UTC+01:00) Brussels, Copenhagen, Madrid, Paris\":20260617T110000\r\n"
             . "DTEND;TZID=\"(UTC+01:00) Brussels, Copenhagen, Madrid, Paris\":20260617T112500";
        $result = normalizeTimezoneIds($ics);
        $this->assertSame(2, substr_count($result, 'TZID=Romance Standard Time'));
    }

    public function testNormalizeTimezoneIds_leavesUnquotedTzidUntouched(): void
    {
        $ics = 'DTSTART;TZID=Romance Standard Time:20260617T110000';
        $result = normalizeTimezoneIds($ics);
        $this->assertSame($ics, $result);
    }

    // -------------------------------------------------------------------------
    // outputIcsContent
    // -------------------------------------------------------------------------

    public function testOutputIcsContent_echoesContent(): void
    {
        ob_start();
        outputIcsContent($this->icsWithEvents);
        $output = ob_get_clean();

        $this->assertSame($this->icsWithEvents, $output);
    }
}
