<?php
declare(strict_types=1);

require_once __DIR__ . '/curl_helper.php';

const DEFAULT_MAX_FILE_SIZE = 819200;

$maxFileSize = getenv('MAX_FILE_SIZE', true);

if ($maxFileSize === false) {
    $maxFileSize = DEFAULT_MAX_FILE_SIZE;
}

define('MAX_FILE_SIZE', $maxFileSize);
define('MISSING_TIMEZONES_FILE', __DIR__ . '/missing_timezones');

// Main execution
if (!defined('TESTING')) {
    try {
        $icsUrl = getIcsUrl();
        validateUrl($icsUrl);
        validateFileContent($icsUrl);
        $icsContent = fetchIcsContent($icsUrl, MAX_FILE_SIZE);
        $icsContent = normalizeTimezoneIds($icsContent);
        $missingTimezones = readMissingTimezones(MISSING_TIMEZONES_FILE);
        $modifiedIcsContent = insertMissingTimezones($icsContent, $missingTimezones);
        outputIcsContent($modifiedIcsContent);
    } catch (Exception $e) {
        die('Error: ' . $e->getMessage());
    }
}

// Strips quotes from TZID parameter values and maps Windows display names to canonical IDs
function normalizeTimezoneIds(string $icsContent): string
{
    static $map = [
        '(UTC-12:00) International Date Line West'                        => 'Dateline Standard Time',
        '(UTC-11:00) Coordinated Universal Time-11'                       => 'UTC-11',
        '(UTC-10:00) Hawaii'                                              => 'Hawaiian Standard Time',
        '(UTC-09:00) Alaska'                                              => 'Alaskan Standard Time',
        '(UTC-08:00) Pacific Time (US & Canada)'                          => 'Pacific Standard Time',
        '(UTC-07:00) Mountain Time (US & Canada)'                         => 'Mountain Standard Time',
        '(UTC-07:00) Arizona'                                             => 'US Mountain Standard Time',
        '(UTC-06:00) Central Time (US & Canada)'                          => 'Central Standard Time',
        '(UTC-06:00) Guadalajara, Mexico City, Monterrey'                 => 'Central Standard Time (Mexico)',
        '(UTC-05:00) Eastern Time (US & Canada)'                          => 'Eastern Standard Time',
        '(UTC-04:00) Georgetown, La Paz, Manaus, San Juan'                => 'SA Western Standard Time',
        '(UTC-03:00) Brasilia'                                            => 'E. South America Standard Time',
        '(UTC+00:00) Dublin, Edinburgh, Lisbon, London'                   => 'GMT Standard Time',
        '(UTC+00:00) Monrovia, Reykjavik'                                 => 'Greenwich Standard Time',
        '(UTC+01:00) Amsterdam, Berlin, Bern, Rome, Stockholm, Vienna'    => 'W. Europe Standard Time',
        '(UTC+01:00) Brussels, Copenhagen, Madrid, Paris'                 => 'Romance Standard Time',
        '(UTC+01:00) Belgrade, Bratislava, Budapest, Ljubljana, Prague'   => 'Central Europe Standard Time',
        '(UTC+01:00) Sarajevo, Skopje, Warsaw, Zagreb'                    => 'Central European Standard Time',
        '(UTC+02:00) Athens, Bucharest'                                   => 'GTB Standard Time',
        '(UTC+02:00) Helsinki, Kyiv, Riga, Sofia, Tallinn, Vilnius'       => 'FLE Standard Time',
        '(UTC+08:00) Perth'                                               => 'Australian Western Standard Time',
    ];

    return preg_replace_callback(
        '/TZID="([^"]+)"/',
        function (array $matches) use ($map): string {
            $tzid = $matches[1];
            return 'TZID=' . ($map[$tzid] ?? $tzid);
        },
        $icsContent
    );
}

// Function to get the ICS URL from the query parameter
function getIcsUrl()
{
    if (!isset($_GET['ics_url']) || empty($_GET['ics_url'])) {
        outputInstructions();
        exit;
    }
    return $_GET['ics_url'];
}

// Function to display usage instructions
function outputInstructions()
{
    echo "<h1>ICS Timezone Fixer</h1>";
    echo "<p>This tool modifies a provided .ics calendar file to include missing timezones, ensuring accurate event times in Google Calendar and other apps.</p>";
    echo "<h2>How to Use:</h2>";
    echo "<ol>";
    echo "<li>Provide an .ics file URL as a query parameter named <code>ics_url</code>.</li>";
    echo "<li>Example usage:</li>";
    echo "<pre>https://ics-changer.great-site.net/?ics_url=https://original-calendar-url.ics</pre>";
    echo "<li>Just use the new URL as a replacement for the original one!</li>";
    echo "</ol>";
    echo "<h2>Note:</h2>";
    echo "<p>The hosted version is provided as-is, without guarantees. If you require reliable access, consider setting up your own server using this code.</p>";
}

// Function to validate the provided URL and enforce HTTPS
function validateUrl($url)
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception('Invalid URL.');
    }

    // Enforce HTTPS
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (strtolower($scheme) !== 'https') {
        throw new Exception('Only HTTPS URLs are allowed.');
    }
}

// Function to validate the file content by downloading a small portion
function validateFileContent($url)
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new Exception('Failed to initialize cURL for partial content download.');
    }

    $partialContent = '';
    $maxBytes = 1024; // Read first 1 KB

    $writeFunction = function ($ch, $data) use (&$partialContent, $maxBytes) {
        $length = strlen($data);
        $partialContent .= $data;
        if (strlen($partialContent) >= $maxBytes) {
            return -1; // Stop reading
        }
        return $length;
    };

    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, CURL_USERAGENT);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writeFunction);
    curl_setopt($ch, CURLOPT_RANGE, '0-' . ($maxBytes - 1));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    // Execute cURL request
    $result = curl_exec($ch);

    if ($result === false && curl_errno($ch) !== CURLE_WRITE_ERROR) {
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        throw new Exception("Failed to read file content. HTTP Code: $httpCode. cURL error: $error");
    }

    curl_close($ch);

    // Check if the content contains 'BEGIN:VCALENDAR'
    if (strpos($partialContent, 'BEGIN:VCALENDAR') === false) {
        throw new Exception('The file does not appear to be a valid ICS file (BEGIN:VCALENDAR not found).');
    }
}

// Function to fetch the ICS content with a size limit
function fetchIcsContent($url, $maxFileSize)
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new Exception('Failed to initialize cURL.');
    }

    $icsContent = '';
    $totalDownloaded = 0;

    // Define the write function callback
    $writeFunction = function ($ch, $data) use (&$icsContent, &$totalDownloaded, $maxFileSize) {
        $length = strlen($data);
        $totalDownloaded += $length;

        if ($totalDownloaded > $maxFileSize) {
            return -1; // Stop reading if limit is exceeded
        } else {
            $icsContent .= $data;
            return $length;
        }
    };

    curl_setopt($ch, CURLOPT_FAILONERROR, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, CURL_USERAGENT);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writeFunction);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10 seconds to connect
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);        // 30 seconds max execution time

    // Execute cURL request
    $result = curl_exec($ch);

    if ($result === false) {
        if (curl_errno($ch) == CURLE_WRITE_ERROR && $totalDownloaded > $maxFileSize) {
            curl_close($ch);
            throw new Exception(sprintf('The ICS file exceeds the maximum allowed size of %d kB.', $maxFileSize / 1024));
        } else {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('Unable to fetch the ICS file. cURL error: ' . $error);
        }
    }

    curl_close($ch);

    return $icsContent;
}

// Function to read the missing timezones from the side file
function readMissingTimezones($filename)
{
    if (!file_exists($filename)) {
        throw new Exception('Missing timezones file not found.');
    }

    $content = file_get_contents($filename);
    if ($content === false) {
        throw new Exception('Unable to read the missing timezones file.');
    }

    return $content;
}

// Function to insert missing timezones into the ICS content
function insertMissingTimezones($icsContent, $missingTimezones)
{
    $pos = strpos($icsContent, 'BEGIN:VEVENT');
    if ($pos === false) {
        // Calendar has no events yet; insert before END:VCALENDAR instead.
        $pos = strpos($icsContent, 'END:VCALENDAR');
        if ($pos === false) {
            throw new Exception('Invalid ICS file: END:VCALENDAR not found.');
        }
    }

    return substr($icsContent, 0, $pos) . $missingTimezones . "\n" . substr($icsContent, $pos);
}

// Returns the HTTP headers that should be sent with the ICS response.
function icsHeaders(): array
{
    return [
        'Content-Type: text/calendar; charset=utf-8',
        'Content-Disposition: attachment; filename="modified_calendar.ics"',
        'Cache-Control: no-cache, must-revalidate',
    ];
}

// Function to output the modified ICS content with appropriate headers
function outputIcsContent($modifiedIcsContent)
{
    foreach (icsHeaders() as $h) {
        header($h);
    }

    echo $modifiedIcsContent;
}

?>
