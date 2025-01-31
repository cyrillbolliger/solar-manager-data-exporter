<?php
declare(strict_types=1);

const VERSION = '1.0.0';

require_once dirname(__DIR__) . '/config.php';

if (!defined('LOGIN_EMAIL')
    || !defined('LOGIN_PASS')
    || !defined('API_URL')
    || !defined('SOLAR_MANAGER_IDS')
    || !defined('SENSOR_RESOLUTION_SEC')
    || !defined('REQUEST_TIMEOUT_SEC')
) {
    throw new RuntimeException('Please define LOGIN_EMAIL, LOGIN_PASS, API_URL, SOLAR_MANAGER_IDS, SENSOR_RESOLUTION_SEC, and REQUEST_TIMEOUT_SEC in ' . dirname(__DIR__) . '/config.php');
}

define('DB_PATH', dirname(__DIR__) . '/storage/db.sqlite');
define('LOG_PATH', dirname(__DIR__) . '/storage/errors.log');

/**
 * @throws RuntimeException
 * @throws JsonException
 */
function requestJson(string $url, array $options): mixed
{
    $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => REQUEST_TIMEOUT_SEC,
        ]
        + $options
        + [CURLOPT_URL => $url];

    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    curl_close($ch);
    if ($response === false) {
        throw new RuntimeException('Curl error: ' . curl_error($ch));
    }
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 400) {
        throw new RuntimeException('API error: ' . $response);
    }
    return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @throws Exception
 */
function getToken(string $email, string $pass): string
{
    $resp = requestJson(API_URL . '/v1/oauth/login', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $pass], JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);

    if (!is_array($resp) || !isset($resp['accessToken'])) {
        throw new RuntimeException("No token found in response:\n" . json_encode($resp, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    return $resp['accessToken'];
}

/**
 * @throws Exception
 */
function apiGet(string $url, string $token): array
{
    $resp = requestJson(API_URL . $url, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
    ]);
    if (!is_array($resp)) {
        throw new RuntimeException("Unexpected response:\n" . json_encode($resp, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
    return $resp;
}

/**
 * @throws Exception
 */
function getSmartMeters(string $token, string $solarManagerId): array
{
    $sensors = apiGet("/v1/info/sensors/$solarManagerId", $token);
    return array_filter($sensors, static fn(array $sensor): bool => $sensor['device_type'] === 'sub-meter');
}

/**
 * @throws Exception
 */
function getSmartMeterNames(): array
{
    $auth = getToken(LOGIN_EMAIL, LOGIN_PASS);

    $data = []; // [smartMeterId => name]

    foreach (SOLAR_MANAGER_IDS as $solarManagerId) {
        $smartMeters = getSmartMeters($auth, $solarManagerId);

        foreach ($smartMeters as $smartMeter) {
            $data[$smartMeter['_id']] = $smartMeter['tag']['name'];
        }
    }

    return $data;
}

/**
 * @throws Exception
 */
function getSmartMeterIds(): array
{
    return array_keys(getSmartMeterNames());
}

function echoUnbuffered(string $string): void
{
    echo $string;
    @ob_flush();
    flush();
}

/**
 * @throws Exception
 */
function getSmartMeterData(string $token, string $sensorId, DateTimeInterface $from, DateTimeInterface $to, int $interval): Generator
{
    if ($to->getTimestamp() - $from->getTimestamp() > 86400) {
        // get max 1 day of data at a time
        $currentFrom = clone $from;
        while ($currentFrom < $to) {
            $currentTo = min($to, $currentFrom->modify('+86400 seconds'));
            $batch = iterator_to_array(getSmartMeterData($token, $sensorId, $currentFrom, $currentTo, $interval));
            usort($batch, static fn(array $a, array $b): int => $a['date'] <=> $b['date']); // oldest first
            foreach ($batch as $entry) {
                yield $entry;
            }
            $currentFrom = $currentFrom->modify('+86400 seconds');
        }
    } else {
        echoUnbuffered("Fetching data for smart meter $sensorId from {$from->format(DATE_ATOM)} to {$to->format(DATE_ATOM)}" . PHP_EOL);
        $fromInApiFormat = $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $toInApiFormat = $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $data = apiGet(
            "/v1/data/sensor/$sensorId/range?from=$fromInApiFormat&to=$toInApiFormat&interval=$interval",
            $token
        );
        foreach ($data as $entry) {
            yield $entry;
        }
    }
}

/**
 * @throws PDOException
 */
function getDbConn(): PDO
{
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

/**
 * @throws PDOException
 */
function ensureTablesExist(PDO $db): void
{
    $db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS smart_meter_data (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    solar_manager_id TEXT NOT NULL,
    smart_meter_id TEXT NOT NULL,
    timestamp INTEGER NOT NULL,
    eWh REAL NOT NULL,
    iWh REAL NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_smart_meter_data_smart_meter_id ON smart_meter_data (smart_meter_id);
CREATE INDEX IF NOT EXISTS idx_smart_meter_data_timestamp ON smart_meter_data (timestamp);
CREATE UNIQUE INDEX IF NOT EXISTS unique_smart_meter_data ON smart_meter_data (smart_meter_id, timestamp);
SQL
    );
}

/**
 * @param PDO $db
 * @param Generator $rows [[solarManagerId => string, smartMeterId => string, timestamp => int, eWh => float, iWh => float], …]
 *
 * @throws PDOException
 */
function insertSmartMeterDataMany(PDO $db, Generator $rows): void
{
    $stmt = $db->prepare(<<<SQL
INSERT INTO smart_meter_data (solar_manager_id, smart_meter_id, timestamp, eWh, iWh)
VALUES (:solar_manager_id, :smart_meter_id, :timestamp, :eWh, :iWh)
ON CONFLICT DO NOTHING;
SQL
    );

    $i = 0;
    $batchSize = 1000;
    foreach ($rows as $row) {
        if ($i++ % $batchSize === 0) {
            $db->beginTransaction();
        }
        $stmt->execute([
            ':solar_manager_id' => $row['solarManagerId'],
            ':smart_meter_id' => $row['smartMeterId'],
            ':timestamp' => $row['timestamp'],
            ':eWh' => $row['eWh'],
            ':iWh' => $row['iWh'],
        ]);
        if ($i % $batchSize === 0) {
            $db->commit();
        }
    }
    $db->commit();
}

/**
 * Get the oldest out of the newest timestamps of the given smart meters.
 *
 * @throws PDOException
 * @throws Exception
 */
function getFromDate(PDO $db, array $activeSmartMeters): ?DateTimeImmutable
{
    $in = implode(',', array_fill(0, count($activeSmartMeters), '?'));
    $stmt = $db->prepare(<<<SQL
WITH newest_data AS (
    SELECT smart_meter_id, MAX(timestamp) as newest 
    FROM smart_meter_data
    WHERE smart_meter_id IN ($in)
    GROUP BY smart_meter_id
) SELECT MIN(newest) FROM newest_data;
SQL
    );
    $stmt->execute($activeSmartMeters);
    $max = $stmt->fetchColumn();
    return $max ? new DateTimeImmutable('@' . $max) : null;
}

/**
 * Get the newest timestamp in the database.
 *
 * @throws PDOException
 * @throws Exception
 */
function getLatestEntryTimestamp(PDO $db): ?DateTimeImmutable
{
    $stmt = $db->query('SELECT MAX(timestamp) FROM smart_meter_data');
    $max = $stmt->fetchColumn();
    return $max ? new DateTimeImmutable('@' . $max) : null;
}

/**
 * @throws Exception
 */
function getSmartMeterDataFromApi(DateTimeInterface $from, DateTimeInterface $to): Generator
{
    $auth = getToken(LOGIN_EMAIL, LOGIN_PASS);

    foreach (SOLAR_MANAGER_IDS as $solarManagerId) {
        $smartMeters = getSmartMeters($auth, $solarManagerId);

        foreach ($smartMeters as $smartMeter) {
            $rows = getSmartMeterData($auth, $smartMeter['_id'], $from, $to, SENSOR_RESOLUTION_SEC);
            foreach ($rows as $entry) {
                yield [
                    'solarManagerId' => $solarManagerId,
                    'smartMeterId' => $smartMeter['_id'],
                    'timestamp' => strtotime($entry['date']),
                    'eWh' => round((float)$entry['eWh'], 2),
                    'iWh' => round((float)$entry['iWh'], 2),
                ];
            }
        }
    }
}

/**
 * @throws PDOException
 */
function getCsvHeader(PDO $db, int $fromTs, int $toTs, array $smartMeterNames): array
{
    $stmt = $db->prepare("SELECT DISTINCT smart_meter_id FROM smart_meter_data WHERE timestamp >= :from AND timestamp < :to ORDER BY smart_meter_id");
    $stmt->execute([':from' => $fromTs, ':to' => $toTs]);

    $headers = ['timestamp' => 'timestamp'];
    while (($smartMeterId = $stmt->fetch(PDO::FETCH_COLUMN)) !== false) {
        $name = trim(($smartMeterNames[$smartMeterId] ?? '') . " ($smartMeterId)");
        $headers += ["{$smartMeterId}_eWh" => "eWh {$name}", "{$smartMeterId}_iWh" => "iWh {$name}"];
    }

    return $headers;
}

/**
 * @throws PDOException
 */
function getExportData(PDO $db, int $fromTs, int $toTs): Generator
{
    $stmt = $db->prepare("SELECT * FROM smart_meter_data WHERE timestamp >= :from AND timestamp < :to ORDER BY timestamp, smart_meter_id");
    $stmt->execute([':from' => $fromTs, ':to' => $toTs]);

    $currentTs = 0;
    $data = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($currentTs === 0) {
            $currentTs = $row['timestamp'];
        }

        if ($currentTs !== $row['timestamp']) {
            yield $data;
            $data = [];
            $currentTs = $row['timestamp'];
        }

        $data['timestamp'] = $data['timestamp'] ?? date('Y-m-d H:i:s', $currentTs);
        $data["{$row['smart_meter_id']}_eWh"] = $row['eWh'];
        $data["{$row['smart_meter_id']}_iWh"] = $row['iWh'];
    }

    yield $data;
}

/**
 * @throws PDOException
 * @throws Exception
 */
function export(PDO $db, ?DateTimeInterface $from, ?DateTimeInterface $to, array $smartMeterNames): void
{
    $fromTs = $from?->getTimestamp() ?? 0;
    $toTs = $to?->getTimestamp() ?? time();

    $header = getCsvHeader($db, $fromTs, $toTs, $smartMeterNames);

    $fileName = 'smart_meter_data_' . ($from?->format('Ymd\THis') ?? 'start') . date('-Ymd\THis', $toTs) . '.csv';

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');

    $stream = fopen('php://output', 'wb');

    fputcsv($stream, $header, escape: '');

    foreach (getExportData($db, $fromTs, $toTs) as $row) {
        // ensure data is in the same order as the header. fill empty cells with empty strings.
        $line = array_map(static fn(string $key) => $row[$key] ?? '', array_keys($header));
        fputcsv($stream, $line, escape: '');
    }

    fclose($stream);
}

function asQueryParams(array $data): string
{
    return implode('&', array_map(static fn(string $v, string $k): string => $k . '=' . urlencode($v), $data, array_keys($data)));
}

function asCliParams(array $data): string
{
    return implode(' ', array_map(static fn(string $v, string $k): string => "--$k=" . escapeshellarg($v), $data, array_keys($data)));
}

/**
 * @throws Exception
 */
function showHelp(?DateTimeInterface $latestEntry): void
{
    $version = VERSION;
    $lastMonth = [
        'from' => date('Y-m-d\T00:00:00T', strtotime('first day of last month')),
        'to' => date('Y-m-d\T23:59:59T', strtotime('last day of last month'))
    ];
    $lastMonthQueryParams = asQueryParams($lastMonth);
    $lastMonthCliParams = asCliParams($lastMonth);
    $currentMonth = ['from' => date('Y-m-d\T00:00:00T', strtotime('first day of this month'))];
    $currentMonthQueryParams = asQueryParams($currentMonth);
    $currentMonthCliParams = asCliParams($currentMonth);
    $lastQuarterNum = (int)ceil(((((int)date('n')) + 9) % 12) / 3);
    $lastQuarterYear = $lastQuarterNum === 4 ? ((int)date('Y')) - 1 : (int)date('Y');
    $lastQuarter = [
        'from' => date('Y-m-d\T00:00:00T', strtotime($lastQuarterYear . '-' . (($lastQuarterNum * 3) - 2) . '-1')),
        'to' => date('Y-m-t\T23:59:59T', strtotime($lastQuarterYear . '-' . ($lastQuarterNum * 3) . '-1'))
    ];
    $lastQuarterQueryParams = asQueryParams($lastQuarter);
    $lastQuarterCliParams = asCliParams($lastQuarter);
    $lastYear = [
        'from' => date('Y-m-d\T00:00:00T', strtotime('first day of last year')),
        'to' => date('Y-m-d\T23:59:59T', strtotime('last day of december last year'))
    ];
    $lastYearQueryParams = asQueryParams($lastYear);
    $lastYearCliParams = asCliParams($lastYear);
    $currentYear = ['from' => date('Y-01-01\T00:00:00T')];
    $currentYearQueryParams = asQueryParams($currentYear);
    $currentYearCliParams = asCliParams($currentYear);
    $interval = SENSOR_RESOLUTION_SEC . 's';
    $latestEntryString = $latestEntry ? $latestEntry->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s T') : 'No data available yet. Update local database first.';

    if (PHP_SAPI !== 'cli') {
        $url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/';
        echo <<<EOF
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="color-scheme" content="light dark">
    <title>Solar Manager Data Exporter</title>
</head>
<body>
<h1>Solar Manager Data Exporter</h1>
<p>Fetches the data ($interval values) from your smart meters regularly and stores them in a local database, where you can export it from.</p>
<h2>Export smart meter data as CSV</h2>
<p>Latest entry: $latestEntryString<br>
&rarr; <a href="{$url}?update">Update</a> (may take a minute or two)
</p>
<h3>Predefined exports</h3>
<ul>
    <li><a href="{$url}?export&{$lastMonthQueryParams}" download>Last month</a></li>
    <li><a href="{$url}?export&{$lastQuarterQueryParams}" download>Last quarter</a></li>
    <li><a href="{$url}?export&{$lastYearQueryParams}" download>Last year</a></li>
    <li><a href="{$url}?export&{$currentYearQueryParams}" download>Current year</a></li>
</ul>
<form action="{$url}" method="get">
    <h3>Custom export</h3>
    <label for="from">From</label>
    <input type="datetime-local" id="from" name="from">
    <label for="to">To</label>
    <input type="datetime-local" id="to" name="to">
    <input type="hidden" name="export">
    <button type="submit">Export</button>
</form>
<h2>Update local database</h2>
<p>This may take a minute or two.</p>
<ul>
    <li><a href="{$url}?update">Latest data</a> (recommended)</li>
    <li><a href="{$url}?update&{$currentMonthQueryParams}">Current month</a></li>
    <li><a href="{$url}?update&{$lastMonthQueryParams}">Last month</a></li>
</ul>
<h2>Error log</h2>
<ul>
    <li><a href="{$url}?logs">Show log</a></li>
</ul>
<h2>Credits</h2>
<p>A free <a href="https://github.com/cyrillbolliger/solar-manager-data-exporter">open source</a> tool by <a href="https://github.com/cyrillbolliger">Cyrill Bolliger</a> licenced under <a href="https://www.gnu.org/licenses/agpl-3.0.en.html#license-text">AGPLv3</a>.</p>
<p>Version: $version</p>
EOF;
    } else {
        $file = 'cli';
        $now = date('Y-m-d\TH:i:sT');
        $nowShellEscaped = escapeshellarg($now);
        echo <<<EOF
NAME
    Solar Manager Data Exporter
    
SYNOPSIS
    {$file} --update [--from=<from>] [--to=<to>]
    {$file} --export [--from=<from>] [--to=<to>]
    {$file} --logs
    {$file} --latest
        
DESCRIPTION
    Fetches the data (every $interval) from your smart meters and stores them in
    a local database, where you can export it from. 
    
    It is recommended to set up a cron job to update the data daily.

OPTIONS
    --update    Update the local database with data from the solar manager API.
    --export    Export local database to CSV file.
    --logs      Show log file.
    --latest    Show the latest timestamp in the local database.
    --from      Timestamp in ISO8601 format (E.g. {$nowShellEscaped}). If 
                omitted, the update resumes at the latest data present or the 
                first of the current month if no data is available. If not 
                provided for export, the export starts with the first record.
    --to        Timestamp in ISO8601 format (E.g. {$nowShellEscaped}). Default:
                now.

EXAMPLES
    - Export last month:     {$file} --export {$lastMonthCliParams}
    - Export last quarter:   {$file} --export {$lastQuarterCliParams}
    - Export last year:      {$file} --export {$lastYearCliParams}
    - Export current year:   {$file} --export {$currentYearCliParams}
    - Export all:            {$file} --export
    
    - Update latest:         {$file} --update
    - Update current month:  {$file} --update {$currentMonthCliParams}
    - Update last month:     {$file} --update {$lastMonthCliParams}
    
    - Show logs:             {$file} --logs
    - Show latest timestamp: {$file} --latest

SEE ALSO
    https://github.com/cyrillbolliger/solar-manager-data-exporter

AUTHOR
    Cyrill Bolliger <mail@cyrill.me>

COPYRIGHT
    AGPLv3

{$file} v{$version}\n
EOF;
    }
}

function main(): void
{
    if (PHP_SAPI === 'cli') {
        // use cli args like query params
        $args = preg_replace('/^--/', '', array_slice($_SERVER['argv'], 1));
        parse_str(implode('&', $args), $_GET);
    }

    try {
        $from = !empty($_GET['from']) ? new DateTimeImmutable($_GET['from']) : null;
        $to = !empty($_GET['to']) ? new DateTimeImmutable($_GET['to']) : new DateTimeImmutable();

        $db = getDbConn();
        ensureTablesExist($db);

        if (isset($_GET['update'])) {
            if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');
            $start = time();
            $from = $from ?? getFromDate($db, getSmartMeterIds()) ?? new DateTimeImmutable(date('Y-m-01'));
            echoUnbuffered(date(DATE_ATOM) . " start fetching data from solar manager (from {$from->format(DATE_ATOM)} to {$to->format(DATE_ATOM)})" . PHP_EOL);
            insertSmartMeterDataMany($db, getSmartMeterDataFromApi($from, $to));
            echoUnbuffered(date(DATE_ATOM) . " success. it took " . (time() - $start) . "s" . PHP_EOL);
        } else if (isset($_GET['export'])) {
            export($db, $from, $to, getSmartMeterNames());
        } else if (isset($_GET['logs'])) {
            if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');
            readfile(LOG_PATH);
        } else if (isset($_GET['latest'])) {
            if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');
            echo (getLatestEntryTimestamp($db)?->format(DATE_ATOM) ?? 'No data available yet. Update local database first.') . PHP_EOL;
        } else {
            showHelp(getLatestEntryTimestamp($db));
            exit(3);
        }
        exit(0);
    } catch (Throwable $e) {
        /** @noinspection ForgottenDebugOutputInspection */
        error_log(date(DATE_ATOM) . ' ' . $e->getMessage() . PHP_EOL, 3, LOG_PATH);
        echo 'Error: ' . $e->getMessage() . PHP_EOL;
        exit(1);
    }
}

main();