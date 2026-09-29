<?php
/**
 * VICIdial leaderboard — PHP 7.4+ with mysqli.
 * Place beside VICIdial's dbconnect_mysqli.php, which supplies $link.
 * Uses the call/sale rules from AST_agent_rapport_summary.php.
 * Set VICIDIAL_TIMEZONE if the PHP server timezone differs from VICIdial.
 * Use a SELECT-only database account. Protect this page using your web server's
 * authentication or internal network access controls; it does not reuse VICIdial login.
 * Reporting timezone MUST match the timezone of VICIdial's stored DATETIME values.
 * Counts are qualifying agent-log rows, not unique leads or verified orders.
 * Current lead status can change historical sale counts, matching the rapport report.
 * Talk time is recorded agent-log talk_sec, not a live running call timer.
 */
declare(strict_types=1);

// require("session_auth.php");

// mysqli_query($link, "SET SESSION group_concat_max_len = 1000000;");
require("session_auth.php");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI']);
    header("Location: admin.php?redirect=$redirect");
    exit;
}

$PHP_AUTH_USER = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
$PHP_AUTH_PW   = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';

if (empty($PHP_AUTH_USER) && !empty($_SESSION['user'])) {
    $PHP_AUTH_USER = $_SESSION['user'];

    $stmt = "SELECT pass FROM vicidial_users WHERE user='" . mysqli_real_escape_string($link, $PHP_AUTH_USER) . "' LIMIT 1";
    $rslt = mysqli_query($link, $stmt);
    if ($rslt && mysqli_num_rows($rslt) > 0) {
        $row = mysqli_fetch_row($rslt);
        $PHP_AUTH_PW = $row[0];
        $_SERVER['PHP_AUTH_USER'] = $PHP_AUTH_USER;
        $_SERVER['PHP_AUTH_PW']   = $PHP_AUTH_PW;
    }
}

$PHP_SELF=$_SERVER['PHP_SELF'];
$PHP_SELF = preg_replace('/\.php.*/i','.php',$PHP_SELF);

$config = [
    'timezone' => getenv('VICIDIAL_TIMEZONE') ?: date_default_timezone_get(),
    'refresh_seconds' => 10,
    'conversion_min_calls' => ['today' => 20, 'week' => 100, 'month' => 400, 'custom' => 20],
    // Initial target until an administrator saves a target on this page.
    'daily_sales_target' => 10,
    'scoring' => [
        'weights' => [
            'calls' => 1, 
            // 'qualified_leads' => 5, 
            'sales' => 20,
            'appointments' => 10, 
            'long_conversations' => 3,
            // 'complaints' => -10, 
            'excessive_pauses' => -5,
        ],
        // Actual agent-log disposition codes. Empty arrays disable these rules.
        'qualified_statuses' => [],
        'appointment_statuses' => ['CALLBK'],
        'complaint_statuses' => [],
        // Each day: first hour allowed; -5 for each started excess hour.
        'pause_allowance_seconds' => 3600,
        'pause_penalty_interval_seconds' => 3600,
    ],
];

require_once __DIR__ . '/scoring_settings.php';


// Shared persistent target for this admin scoreboard (no VICIdial table changes).
$targetFile = __DIR__ . '/admin_sales_target.json';
if (is_file($targetFile)) {
    $savedTarget = json_decode((string) file_get_contents($targetFile), true);
    if (is_array($savedTarget) && isset($savedTarget['target'])
        && is_int($savedTarget['target']) && $savedTarget['target'] >= 1 && $savedTarget['target'] <= 1000000) {
        $config['daily_sales_target'] = $savedTarget['target'];
    }
}
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['scoreboard_target_csrf'])) {
    $_SESSION['scoreboard_target_csrf'] = bin2hex(random_bytes(32));
}
$targetCsrf = $_SESSION['scoreboard_target_csrf'];
$targetAdmin = $_SESSION['user'] ?? null;
session_write_close();

$version = '2.14-692c';
$build = '230927-2036';
$php_script = 'vicidial.php';
$mel = 1; # Mysql Error Log enabled = 1
$mysql_log_count = 103;
$one_mysql_log = 0;
$DB = 0;
$conf_table = "vicidial_conferences";

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

/** Prepared SELECT helper; bind_result also works without mysqlnd. */
function selectRows(mysqli $link, string $sql, array $params = []): array
{
    $stmt = $link->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Query preparation failed: ' . $link->error);
    }
    try {
        if ($params && !$stmt->bind_param(str_repeat('s', count($params)), ...$params)) {
            throw new RuntimeException('Query parameter binding failed: ' . $stmt->error);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Query execution failed: ' . $stmt->error);
        }
        $metadata = $stmt->result_metadata();
        if (!$metadata) {
            throw new RuntimeException('Query did not return result metadata.');
        }
        $values = [];
        $bindings = [];
        foreach ($metadata->fetch_fields() as $field) {
            $values[$field->name] = null;
            $bindings[] = &$values[$field->name];
        }
        $metadata->free();
        $stmt->bind_result(...$bindings);
        $rows = [];
        while (($fetched = $stmt->fetch()) === true) {
            $row = [];
            foreach ($values as $key => $value) {
                $row[$key] = $value;
            }
            $rows[] = $row;
        }
        if ($fetched === false) {
            throw new RuntimeException('Query result fetch failed: ' . $stmt->error);
        }
        return $rows;
    } finally {
        $stmt->close();
    }
}

/** Dates and campaign use the same GET names as the rapport report. */
function reportFilters(array $config, array $input): array
{
    $timezone = new DateTimeZone($config['timezone']);
    $today = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    $period = $input['period'] ?? ((isset($input['begin_date']) || isset($input['end_date'])) ? 'custom' : 'today');
    if (!is_string($period) || !in_array($period, ['today', 'week', 'month', 'custom'], true)) {
        throw new InvalidArgumentException('Invalid standings period.');
    }
    if (($input['embedded'] ?? '') === '1') {
        $period = 'today';
        $input['begin_date'] = $today;
        $input['end_date'] = $today;
        $input['campaign_id'] = '--ALL--';
    }
    if ($period !== 'custom') {
        $date = new DateTimeImmutable($today, $timezone);
        $start = $date;
        if ($period === 'week') $start = $date->modify('-' . ((int) $date->format('N') - 1) . ' days');
        if ($period === 'month') $start = $date->modify('first day of this month');
        $input['begin_date'] = $start->format('Y-m-d');
        $input['end_date'] = $today;
    }
    $filters = ['period' => $period];
    foreach (['begin_date', 'end_date'] as $key) {
        $value = $input[$key] ?? $today;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Dates must use YYYY-MM-DD.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Dates must use YYYY-MM-DD.');
        }
        $filters[$key] = $value;
    }
    if ($filters['begin_date'] > $filters['end_date']) {
        throw new InvalidArgumentException('Start date must be on or before end date.');
    }
    $campaign = $input['campaign_id'] ?? '--ALL--';
    if (!is_string($campaign) || !preg_match('/\A[-_a-zA-Z0-9]{1,20}\z/', $campaign)) {
        throw new InvalidArgumentException('Invalid campaign ID.');
    }
    $filters['campaign_id'] = $campaign;
    return $filters;
}

/** Build optional counters using bound parameters; unknown codes earn no points. */
function scoringCounters(array $scoring): array
{
    $expressions = ['SUM(CASE WHEN val.talk_sec > 120 THEN 1 ELSE 0 END) AS long_conversations'];
    $params = [];
    foreach (['qualified_statuses' => 'qualified_leads', 'appointment_statuses' => 'appointments',
        'complaint_statuses' => 'complaints'] as $key => $alias) {
        $codes = $scoring[$key];
        if (!$codes) {
            $expressions[] = "0 AS $alias";
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $expressions[] = "SUM(CASE WHEN val.status IN ($placeholders) THEN 1 ELSE 0 END) AS $alias";
        $params = array_merge($params, $codes);
    }
    return [implode(",\n        ", $expressions), $params];
}

/** Count started excess hours AFTER the daily allowance (exactly 1h is free). */
function dailyPauseUnits(int $seconds, array $scoring): int
{
    $excess = max(0, $seconds - $scoring['pause_allowance_seconds']);
    return (int) ceil($excess / $scoring['pause_penalty_interval_seconds']);
}

/** Recompute from the filtered snapshot; refreshes never accumulate points. */
function agentPoints(array $counts, array $weights): array
{
    $breakdown = [];
    foreach ($weights as $metric => $weight) {
        $breakdown[$metric] = (int) ($counts[$metric] ?? 0) * $weight;
    }
    return ['score' => array_sum($breakdown), 'score_breakdown' => $breakdown];
}

function snapshot(array $config, mysqli $link, array $filters): array
{
    $config['scoring']['weights'] = loadScoringWeights($link);
    $now = new DateTimeImmutable('now', new DateTimeZone($config['timezone']));
    // Preserve the rapport report's inclusive 00:00:01 through 23:59:59 range.
    $params = [$filters['begin_date'] . ' 00:00:01', $filters['end_date'] . ' 23:59:59'];
    $campaignCondition = '';
    if ($filters['campaign_id'] !== '--ALL--') {
        $campaignCondition = ' AND val.campaign_id = ?';
        $params[] = $filters['campaign_id'];
    }
    [$scoreSql, $scoreParams] = scoringCounters($config['scoring']);
    $rows = selectRows($link, "SELECT val.user, vu.full_name,
        $scoreSql,
        SUM(CASE WHEN val.talk_sec > 2 THEN 1 ELSE 0 END) AS calls,
        SUM(CASE WHEN val.talk_sec > 120 THEN 1 ELSE 0 END) AS calls_over_two_minutes,
        SUM(GREATEST(COALESCE(val.talk_sec, 0), 0)) AS talk_seconds,
        SUM(CASE WHEN val.status = 'SALE' OR vls.status = 'SALE'
            THEN 1 ELSE 0 END) AS sales
        FROM vicidial_agent_log val
        LEFT JOIN vicidial_users vu ON val.user = vu.user
        LEFT JOIN vicidial_list vls ON val.lead_id = vls.lead_id
        WHERE val.event_time >= ? AND val.event_time <= ? $campaignCondition
        GROUP BY val.user, vu.full_name", array_merge($scoreParams, $params));
    // Aggregate by day BEFORE applying the allowance; never combine days first.
    // All pause reasons count. Campaign filtering follows the selected report scope.
    $pauseParams = $params;
    $pauseParams[0] = $filters['begin_date'] . ' 00:00:00';
    $pauseRows = selectRows($link, "SELECT val.user, DATE(val.event_time) AS pause_date,
        SUM(GREATEST(COALESCE(val.pause_sec, 0), 0)) AS pause_seconds
        FROM vicidial_agent_log val
        WHERE val.event_time >= ? AND val.event_time <= ? $campaignCondition
        GROUP BY val.user, DATE(val.event_time)", $pauseParams);
    $pauses = [];
    foreach ($pauseRows as $pauseRow) {
        $id = (string) $pauseRow['user'];
        $seconds = (int) $pauseRow['pause_seconds'];
        if (!isset($pauses[$id])) {
            $pauses[$id] = ['seconds' => 0, 'units' => 0];
        }
        $pauses[$id]['seconds'] += $seconds;
        $pauses[$id]['units'] += dailyPauseUnits($seconds, $config['scoring']);
    }
    $agents = [];
    foreach ($rows as $row) {
        $calls = (int) $row['calls'];
        $sales = (int) $row['sales'];
        // Retain zero-call rows and missing users, like the rapport report's LEFT JOIN.
        $agent = [
            'user' => (string) $row['user'],
            'agent' => trim((string) $row['full_name']) ?: ((string) $row['user'] ?: 'Unknown agent'),
            'calls' => $calls, 'sales' => $sales,
            'calls_over_two_minutes' => (int) $row['calls_over_two_minutes'],
            'talk_seconds' => (int) $row['talk_seconds'],
            'conversion' => $calls ? round(100 * $sales / $calls, 1) : null,
        ];
        foreach (['qualified_leads', 'appointments', 'long_conversations', 'complaints'] as $metric) {
            $agent[$metric] = (int) $row[$metric];
        }
        $agent['pause_seconds'] = $pauses[$agent['user']]['seconds'] ?? 0;
        $agent['excessive_pauses'] = $pauses[$agent['user']]['units'] ?? 0;
        $agents[] = array_merge($agent, agentPoints($agent, $config['scoring']['weights']));
    }
    usort($agents, static function (array $a, array $b): int {
        return ($b['score'] <=> $a['score'])
            ?: ($b['sales'] <=> $a['sales'])
            ?: (($b['conversion'] ?? -1) <=> ($a['conversion'] ?? -1))
            ?: ($b['calls'] <=> $a['calls']) ?: strcmp($a['user'], $b['user']);
    });
    $campaigns = selectRows($link, 'SELECT campaign_id, campaign_name FROM vicidial_campaigns ORDER BY campaign_name');
    $calls = array_sum(array_column($agents, 'calls'));
    $sales = array_sum(array_column($agents, 'sales'));
    // Today's team goal is independent of the leaderboard's historical filters.
    $today = $now->format('Y-m-d');
    $todayRows = selectRows($link, "SELECT
        COALESCE(SUM(CASE WHEN val.status = 'SALE' OR vls.status = 'SALE'
            THEN 1 ELSE 0 END), 0) AS sales
        FROM vicidial_agent_log val
        LEFT JOIN vicidial_list vls ON val.lead_id = vls.lead_id
        WHERE val.event_time >= ? AND val.event_time < ?",
        [$today . ' 00:00:00', $now->modify('+1 day')->format('Y-m-d') . ' 00:00:00']);
    $todaySales = (int) $todayRows[0]['sales'];
    $target = max(0, (int) $config['daily_sales_target']);
    $dailyGoal = ['date' => $today, 'sales' => $todaySales, 'target' => $target,
        'progress' => $target > 0 ? round(100 * $todaySales / $target, 1) : null];
    return [
        'daily_goal' => $dailyGoal,
        'weights' => $config['scoring']['weights'],
        'agents' => $agents, 'campaigns' => $campaigns,
        'filters' => $filters, 'timezone' => $config['timezone'],
        'updated_at' => $now->format('Y-m-d H:i:s T'),
        'totals' => ['calls' => $calls, 'sales' => $sales, 'agents' => count($agents),
            'conversion' => $calls ? round(100 * $sales / $calls, 1) : null],
    ];
}

function salesHistory(mysqli $link, array $config, string $agent, string $campaign): array
{
    if (!preg_match('/\A[-_a-zA-Z0-9]{1,20}\z/', $agent)) {
        throw new InvalidArgumentException('Invalid agent ID.');
    }
    $today = new DateTimeImmutable('today', new DateTimeZone($config['timezone']));
    $start = $today->modify('-6 days');
    $params = [$agent, $start->format('Y-m-d H:i:s'), $today->modify('+1 day')->format('Y-m-d H:i:s')];
    $condition = '';
    if ($campaign !== '--ALL--') { $condition = ' AND val.campaign_id = ?'; $params[] = $campaign; }
    $weights = loadScoringWeights($link);
    [$counterSql, $counterParams] = scoringCounters($config['scoring']);
    $rows = selectRows($link, "SELECT DATE(val.event_time) AS sale_date,
        SUM(CASE WHEN val.status = 'SALE' THEN 1 ELSE 0 END) AS sales,
        SUM(CASE WHEN val.talk_sec > 2 THEN 1 ELSE 0 END) AS calls,
        SUM(GREATEST(COALESCE(val.pause_sec, 0), 0)) AS pause_seconds,
        $counterSql
        FROM vicidial_agent_log val WHERE val.user = ?
        AND val.event_time >= ? AND val.event_time < ? $condition
        GROUP BY DATE(val.event_time) ORDER BY sale_date", array_merge($counterParams, $params));
    $counts = [];
    foreach ($rows as $row) $counts[$row['sale_date']] = $row;
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $date = $start->modify('+' . $i . ' days')->format('Y-m-d');
        $row = $counts[$date] ?? [];
        $day = ['date' => $date];
        foreach (['sales', 'calls', 'appointments', 'long_conversations', 'qualified_leads', 'complaints', 'pause_seconds'] as $metric) {
            $day[$metric] = (int) ($row[$metric] ?? 0);
        }
        $day['excessive_pauses'] = dailyPauseUnits($day['pause_seconds'], $config['scoring']);
        $day['points'] = agentPoints($day, $weights)['score'];
        $day['conversion'] = $day['calls'] > 0 ? round(100 * $day['sales'] / $day['calls'], 1) : null;
        $days[] = $day;
    }
    return ['agent' => $agent, 'days' => $days, 'timezone' => $config['timezone'], 'campaign' => $campaign,
        'weights' => $weights];
}

if (isset($_GET['data']) || isset($_GET['save_target']) || isset($_GET['save_weights']) || ($_GET['action'] ?? '') === 'sales_history') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $historyRequest = ($_GET['action'] ?? '') === 'sales_history';
        if ($historyRequest && (!is_string($targetAdmin) || $targetAdmin === '')) {
            http_response_code(401); echo json_encode(['error' => 'Sign in through admin.php to view sales history.']); exit;
        }
        $savingTarget = isset($_GET['save_target']);
        $savingWeights = isset($_GET['save_weights']);
        if ($savingTarget || $savingWeights) {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                header('Allow: POST'); http_response_code(405);
                echo json_encode(['error' => 'Use POST to save settings.']); exit;
            }
            if (!is_string($targetAdmin) || $targetAdmin === '') {
                http_response_code(401);
                echo json_encode(['error' => 'Sign in through admin.php before saving settings.']); exit;
            }
            if (!is_string($_POST['csrf'] ?? null) || !hash_equals($targetCsrf, $_POST['csrf'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Session changed. Reload this page and try again.']); exit;
            }
            if ($savingWeights) {
                $newWeights = validateScoringWeights($_POST['weights'] ?? null);
            } else {
            $newTarget = filter_var($_POST['target'] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
            if ($newTarget === false) throw new InvalidArgumentException('Enter a whole-number target from 1 to 1,000,000.');
            }
        }
        $filters = reportFilters($config, $_GET);
        $connectionFile = __DIR__ . '/dbconnect_mysqli.php';
        if (!is_file($connectionFile)) {
            throw new RuntimeException('Missing dbconnect_mysqli.php beside scoreboard.php.');
        }
        // Load in global scope for compatibility with VICIdial's connection script.
        // Discard legacy diagnostic output so the endpoint remains valid JSON.
        ob_start();
        try {
            require_once __DIR__ . '/dbconnect_mysqli.php';
        } finally {
            ob_end_clean();
        }
        if (!isset($link) || !($link instanceof mysqli) || $link->connect_errno) {
            throw new RuntimeException('dbconnect_mysqli.php did not supply a working $link connection.');
        }
        if (!$link->set_charset('utf8mb4')) {
            throw new RuntimeException('Unable to set database connection character set.');
        }
        if ($historyRequest) {
            $viewer = selectRows($link, 'SELECT active, user_level, view_reports FROM vicidial_users WHERE user = ? LIMIT 1', [$targetAdmin]);
            if (!$viewer || $viewer[0]['active'] !== 'Y'
                || ((int) $viewer[0]['user_level'] < 8 && (int) $viewer[0]['view_reports'] !== 1)) {
                http_response_code(403); echo json_encode(['error' => 'Report access is required to view sales history.']); exit;
            }
            if (!is_string($_GET['agent'] ?? null)) throw new InvalidArgumentException('Invalid agent ID.');
            echo json_encode(salesHistory($link, $config, $_GET['agent'], $filters['campaign_id']), JSON_THROW_ON_ERROR);
            exit;
        }
        if ($savingTarget || $savingWeights) {
            $permission = selectRows($link, 'SELECT user_level, active FROM vicidial_users WHERE user = ? LIMIT 1', [$targetAdmin]);
            if (!$permission || (int) $permission[0]['user_level'] < 8 || $permission[0]['active'] !== 'Y') {
                http_response_code(403);
                echo json_encode(['error' => 'An active administrator with user level 8 or higher must save settings.']); exit;
            }
            if ($savingWeights) {
                saveScoringWeights($link, $newWeights, $targetAdmin);
                echo json_encode(['weights' => $newWeights]); exit;
            }
            // Write beside the final file, then atomically replace it so refreshes see complete JSON.
            $temporaryTarget = tempnam(__DIR__, '.sales-target-');
            if ($temporaryTarget === false) throw new RuntimeException('Cannot create target settings file.');
            try {
                $json = json_encode(['target' => $newTarget], JSON_THROW_ON_ERROR);
                if (file_put_contents($temporaryTarget, $json, LOCK_EX) === false || !rename($temporaryTarget, $targetFile)) {
                    throw new RuntimeException('Cannot save target settings. Check directory permissions.');
                }
            } finally {
                if (is_file($temporaryTarget)) unlink($temporaryTarget);
            }
            echo json_encode(['target' => $newTarget]); exit;
        }
        echo json_encode(snapshot($config, $link, $filters), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (InvalidArgumentException $error) {
        http_response_code(400);
        echo json_encode(['error' => $error->getMessage()]);
    } catch (Throwable $error) {
        error_log('VICIdial leaderboard: ' . $error->getMessage());
        http_response_code(503);
        echo json_encode(['error' => (isset($_GET['save_target']) || isset($_GET['save_weights'])) ? 'Could not save settings. Check server logs, database permissions, and target-file permissions.' : 'Leaderboard unavailable. Check database settings and the PHP server error log.']);
    }
    exit;
}
$filterError = '';
try {
    $filters = reportFilters($config, $_GET);
} catch (InvalidArgumentException $error) {
    $filterError = $error->getMessage();
    $filters = reportFilters($config, []);
}
function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Today’s Top Performers · VICIdial</title>
<style>
:root { color-scheme: dark; --bg: #001216; --panel: #001a20; --teal: #00efd1; --blue: #00aeef; --muted: #a6b8bd; --line: #07353b; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { background: var(--bg); color: #f4fafb; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-height: 100vh; padding: 36px 0 48px; }
main { width: 83.333%; max-width: 1800px; margin: auto; }
.page-header { position: relative; text-align: center; padding: 0 0 26px; margin-bottom: 36px; border-bottom: 1px solid #07514f; }
h1 { display: inline-block; background: linear-gradient(100deg,var(--teal),var(--blue)); color: var(--teal); background-clip: text; -webkit-background-clip: text; -webkit-text-fill-color: transparent; text-transform: uppercase; letter-spacing: 3px; font-size: clamp(25px,2.6vw,42px); line-height: 1.25; font-weight: 800; }
.back { position: absolute; left: -6.25vw; top: 0; }
.btn-back, button { display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: 7px; padding: 13px 22px; background: var(--teal); color: #001216; text-decoration: none; text-transform: uppercase; font-size: 14px; font-weight: 800; letter-spacing: .65px; cursor: pointer; min-height: 44px; transition: background .15s; }
button:hover, .btn-back:hover { background: #5affdf; }
button:disabled { opacity: .5; cursor: wait; }
#refresh { background: var(--blue); color: white; }
#refresh:hover { background: #24bcf5; }
form { display: grid; grid-template-columns: minmax(220px,2fr) repeat(2,minmax(160px,1fr)) auto auto; gap: 24px; align-items: end; background: var(--panel); padding: 26px 24px; border: 1px solid var(--teal); border-radius: 14px; margin-bottom: 36px; box-shadow: 0 12px 30px #0003; }
label { display: flex; flex-direction: column; gap: 10px; color: var(--teal); font-size: 13px; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; min-width: 0; }
select, input[type=date] { width: 100%; min-width: 0; height: 46px; border: 1px solid #08605b; border-radius: 7px; background: var(--bg); color: #f4fafb; font: 500 15px 'Segoe UI',sans-serif; padding: 10px 16px; }
select option { background: var(--panel); color: white; }
input:focus, select:focus, button:focus-visible, a:focus-visible, summary:focus-visible { outline: 2px solid var(--blue); outline-offset: 3px; }
.cards { display: grid; grid-template-columns: repeat(4,1fr); gap: 24px; margin-bottom: 32px; }
.card { position: relative; overflow: hidden; border: 1px solid var(--line); background: var(--panel); border-radius: 14px; padding: 24px; min-height: 112px; }
.card::before { content: ''; position: absolute; top: 0; bottom: 0; left: 0; width: 5px; background: var(--teal); }
.card span { display: block; color: var(--muted); font-size: 13px; text-transform: uppercase; }
.card strong { display: block; margin-top: 10px; color: #f4fafb; font-size: 30px; line-height: 1.1; font-variant-numeric: tabular-nums; }
.card:first-child strong { color: var(--teal); }
.card:nth-child(3) strong { color: #ffb000; }
h3 { font-size: 16px; color: var(--teal); font-weight: 600; margin-bottom: 10px; }
.note-box { font-size: 12px; color: var(--muted); line-height: 1.7; margin-bottom: 20px; }
#date { margin: 10px 0 0; }
.note-box strong { font-weight: 400; }
.note-box em { font-style: normal; }
.scroll { overflow-x: auto; border-radius: 14px; background: var(--panel); box-shadow: 0 14px 30px #0003; }
table { border-collapse: collapse; width: 100%; text-align: left; white-space: nowrap; }
th { background: #053436; color: var(--teal); font-size: 12px; letter-spacing: 1px; text-transform: uppercase; padding: 19px 22px; border-bottom: 2px solid #08605b; }
td { padding: 20px 22px; font-size: 15px; border-bottom: 1px solid #0d2b31; font-variant-numeric: tabular-nums; }
tbody tr:last-child td { border-bottom: 0; }
tbody tr:hover { background: #03262c; }
.name { font-weight: 700; }
.user { display: block; color: var(--muted); font-size: 12px; font-weight: 400; margin-top: 3px; }
td:nth-child(5), .sales { color: var(--teal); font-weight: 700; }
.score { color: #ffb000; font-weight: 800; }
.score details { font-size: 12px; font-weight: 400; margin-top: 7px; color: var(--muted); }
.score summary { cursor: pointer; color: var(--blue); }
.score details div { padding: 4px 0; }
.rules { margin: 24px 0 16px; padding: 16px 20px; background: var(--panel); border: 1px solid var(--line); border-radius: 10px; font-size: 13px; line-height: 1.8; color: var(--muted); }
.rules summary { cursor: pointer; color: var(--teal); font-weight: 600; }
.rules p { margin-top: 8px; }
.empty { padding: 40px; color: var(--muted); text-align: center; }
.status { font-size: 12px; color: var(--muted); margin: 16px 0; }
.error { color: #ff8797; }
footer { color: #839bA1; font-size: 12px; line-height: 1.8; margin-top: 20px; }
@media (max-width:1250px) { .back { position: static; text-align: left; margin-bottom: 20px; } form { grid-template-columns: 2fr 1fr 1fr; gap: 18px; } main { width: 90%; } }
@media (max-width:700px) { body { padding-top: 20px; } main { width: 94%; } .page-header { margin-bottom: 24px; } form { grid-template-columns: 1fr 1fr; padding: 18px; gap: 16px; } form label:first-child { grid-column: 1 / -1; } .cards { grid-template-columns: 1fr 1fr; gap: 12px; } .card { padding: 18px; } .card span { font-size: 11px; } .card strong { font-size: 26px; } th, td { padding: 16px; } }
@media (max-width:420px) { form { grid-template-columns: 1fr; } }
/* Leader spotlight and achievement styling */
form { padding: 16px 20px; gap: 16px; margin-bottom: 24px; border-color: var(--line); }
.cards { gap: 16px; margin-bottom: 26px; }
.card { min-height: 90px; padding: 18px 22px; }
.spotlight-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 28px 0 16px; }
.spotlight-heading h2 { font-size: 23px; letter-spacing: -.4px; }
.spotlight-heading span { color: var(--muted); font-size: 12px; }
.leaders { display: grid; grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); gap: 18px; margin-bottom: 28px; }
.leader { position: relative; padding: 24px; border: 1px solid #275259; border-radius: 18px; background: linear-gradient(135deg,#082c33,#001a20); overflow: hidden; }
.leader:first-child { border-color: #9b7934; background: radial-gradient(ellipse at top right,#65501c55,transparent 70%),#001a20; }
.leader-place { color: #b7cdd1; font-size: 12px; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 20px; }
.leader:first-child .leader-place { color: #ffce70; }
.avatar { display: inline-flex; width: 46px; height: 46px; align-items: center; justify-content: center; border-radius: 14px; background: #00efd11c; border: 1px solid #00efd14d; color: var(--teal); font-size: 17px; font-weight: 800; margin-right: 12px; flex-shrink: 0; }
.leader-person { display: flex; align-items: center; min-width: 0; }
.leader-name { font-size: 19px; overflow-wrap: anywhere; }
.leader-points { display: block; color: #ffce70; font-size: 46px; line-height: 1.1; font-weight: 800; margin: 20px 0 8px; font-variant-numeric: tabular-nums; }
.leader-points small { font-size: 13px; font-weight: 500; color: var(--muted); margin-left: 7px; }
.leader-stats { color: var(--muted); font-size: 13px; margin-bottom: 14px; }
.badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 9px; white-space: normal; }
.badge { display: inline-block; border-radius: 20px; padding: 5px 9px; color: #8ff8dd; background: #00efd110; border: 1px solid #00efd130; font-size: 11px; font-weight: 600; }
.badge.sale { color: #ffda8d; background: #ffb00010; border-color: #ffb00040; }
.score { font-size: 25px; color: #ffce70; }
.score details { font-size: 12px; }
#milestone { color: #ffda8d; font-size: 13px; margin-bottom: 14px; }
#milestone:empty { display: none; }
.badge.earned { animation: celebrate 1s ease-out; }
@keyframes celebrate { 0% { transform: scale(.9); box-shadow: 0 0 0 0 #00efd155; } 50% { transform: scale(1.06); } 100% { transform: scale(1); box-shadow: 0 0 0 10px transparent; } }
@media(prefers-reduced-motion:reduce) { .badge.earned { animation: none; } }
@media(max-width:700px) { .leaders { grid-template-columns: 1fr; } .spotlight-heading { align-items: flex-start; flex-direction: column; } .leader-points { font-size: 38px; } }
.daily-goal { padding: 24px; border: 1px solid #177268; border-radius: 18px; background: linear-gradient(110deg,#052c30,var(--panel)); margin-bottom: 26px; }
.goal-heading { display: flex; justify-content: space-between; align-items: center; gap: 16px; }
.goal-heading h2 { font-size: 21px; color: var(--teal); }
.goal-heading strong { font-size: 32px; color: #ffce70; }
.goal-meta { font-size: 13px; color: var(--muted); margin-top: 8px; }
.goal-track { height: 16px; background: #12353b; border-radius: 12px; overflow: hidden; margin-top: 18px; }
.goal-fill { height: 100%; width: 0; background: linear-gradient(90deg,var(--teal),var(--blue)); transition: width .6s ease; }
@media(prefers-reduced-motion:reduce) { .goal-fill { transition: none; } }
.embedded #filters, .embedded .back { display: none; }
.embedded { padding: 20px 0; }
.embedded main { width: 94%; }
@media (max-width: 767px) {
    .embedded { padding: 16px 0; }
    .embedded main { width: auto; margin: 0 12px; }
    .embedded .page-header { padding-bottom: 16px; margin-bottom: 20px; }
    .embedded h1 { font-size: 24px; letter-spacing: 1px; }
    .daily-goal { padding: 16px; }
    .goal-heading { align-items: flex-start; gap: 12px; }
    .goal-heading h2 { font-size: 18px; }
    .goal-heading strong { font-size: 26px; flex-shrink: 0; }
    .goal-meta, .note-box, footer { overflow-wrap: anywhere; }
    .leaders { grid-template-columns: 1fr; gap: 12px; }
    .leader { padding: 18px; }
    .cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .card { min-width: 0; }
    .card strong { font-size: 26px; overflow-wrap: anywhere; }
    .scroll { max-width: 100%; overscroll-behavior-x: contain; }
    .scroll table { min-width: 850px; }
    th, td { padding: 14px 12px; }
    .score summary, .rules summary { min-height: 44px; padding: 10px 0; }
    .rules { padding: 12px 16px; }
}
@media (max-width: 767px) {
    /* Hide columns 3–7; retain Rank, Agent, and Points. */
    .scroll table th:nth-child(n+3):nth-child(-n+7),
    .scroll table td:nth-child(n+3):nth-child(-n+7) {
        display: none;
    }

    /* Override the existing 850px mobile table width. */
    .scroll table {
        min-width: 0;
        width: 100%;
        table-layout: fixed;
        white-space: normal;
    }

    .scroll table th:first-child {
        width: 20%;
    }

    .scroll table th:nth-child(2) {
        width: 45%;
    }

    .scroll table th:last-child {
        width: 35%;
    }

    .scroll table th,
    .scroll table td {
        padding: 14px 10px;
        overflow-wrap: anywhere;
    }
}
#agentDetailPopup { position: fixed; inset: 0; margin: auto; width: calc(100% - 24px); max-width: 480px; max-height: 85vh; max-height: 85dvh; overflow-y: auto; padding: 20px; border: 1px solid var(--teal); border-radius: 16px; background: var(--panel); color: #fff; }
#agentDetailPopup:not([open]) { display: none !important; }
#agentDetailPopup[open] { display: block !important; }
#agentDetailPopup::backdrop { background: rgba(0,0,0,.75); }
.agent-detail-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 12px; }
#agentDetailTitle { color: var(--teal); font-size: 20px; overflow-wrap: anywhere; }
#agentDetailClose { min-height: 44px; flex-shrink: 0; }
#agentDetailUpdated { color: var(--muted); font-size: 12px; margin-bottom: 16px; }
#agentDetailFields > div { display: flex; justify-content: space-between; gap: 16px; padding: 12px 0; border-bottom: 1px solid var(--line); }
#agentDetailFields dt { color: var(--muted); }
#agentDetailFields dd { margin: 0; text-align: right; font-weight: 700; overflow-wrap: anywhere; }
@media (max-width: 767px) {
    #rows tr[data-agent-row] { cursor: pointer; }
    #rows tr[data-agent-row]:focus-visible { outline: 2px solid var(--teal); outline-offset: -2px; }
}
@media (max-width: 767px) {
    .spotlight-heading,
    #leaders {
        display: none;
    }
}
#target-form { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; padding: 0; margin: 16px 0 0; border: 0; box-shadow: none; background: transparent; }
#target-input { width: 160px; min-height: 44px; padding: 10px 12px; border: 1px solid var(--teal); border-radius: 7px; background: var(--bg); color: white; font: inherit; }
#target-message { color: var(--muted); font-size: 13px; margin-top: 10px; }
.scoring-panel { border: 1px solid var(--line); border-radius: 14px; background: var(--panel); padding: 18px 22px; margin-bottom: 24px; }
.scoring-panel summary { cursor: pointer; color: var(--teal); font-weight: 700; min-height: 32px; }
#scoring-form { display: grid; grid-template-columns: repeat(auto-fit,minmax(180px,1fr)); gap: 16px; padding: 16px 0; margin: 0; border: 0; box-shadow: none; background: transparent; }
#scoring-form label { display: flex; flex-direction: column; gap: 8px; }
#scoring-form input { width: 100%; min-height: 44px; padding: 10px; border: 1px solid var(--teal); border-radius: 7px; background: var(--bg); color: white; font-size: 16px; }
#scoring-message { color: var(--muted); font-size: 13px; margin-top: 10px; }
.embedded .scoring-panel { display: none; }
#agentDetailPopup { max-width: 680px; }
#rows tr[data-agent-row] { cursor: pointer; }
#rows tr[data-agent-row]:focus-visible { outline: 2px solid var(--teal); outline-offset: -2px; }
.sales-history { margin-top: 24px; border-top: 1px solid var(--line); padding-top: 20px; }
#salesHistoryStatus { color: var(--muted); font-size: 13px; line-height: 1.6; margin: 10px 0; }
#salesHistoryChart { display: grid; grid-template-columns: repeat(7,minmax(0,1fr)); gap: 8px; }
.history-column { min-width: 0; text-align: center; }
.history-track { height: 140px; display: flex; align-items: flex-end; justify-content: center; border-bottom: 1px solid var(--line); }
.history-bar { width: 70%; background: var(--teal); border-radius: 5px 5px 0 0; }
.history-column:last-child .history-bar { background: #ffce70; }
.history-count { display: block; margin-bottom: 6px; color: white; font-weight: 700; }
.history-date { display: block; font-size: 11px; margin-top: 8px; color: var(--muted); }
.history-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
.history-tabs button { min-height: 44px; padding: 10px 14px; background: transparent; border: 1px solid var(--teal); color: var(--teal); }
.history-tabs button[aria-pressed="true"] { background: var(--teal); color: var(--bg); }
.history-track { position: relative; display: block; }
.history-bar { position: absolute; left: 15%; border-radius: 4px; }
.history-bar.negative { background: #ff8797 !important; }
.history-baseline { position: absolute; left: 0; right: 0; border-top: 1px solid #91afb9; }
.history-ratio { display: block; font-size: 10px; color: var(--muted); margin-top: 5px; overflow-wrap: anywhere; }
.standings-periods { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
.standings-period { display: inline-flex; min-height: 44px; align-items: center; padding: 10px 20px; border: 1px solid var(--teal); border-radius: 9px; color: var(--teal); font-weight: 700; text-decoration: none; }
.standings-period[aria-current="page"] { background: var(--teal); color: var(--bg); }
.standings-period:hover { background: #07514f; color: white; }
.embedded .standings-periods, .embedded .standings-note { display: none; }
.champion-person { margin: 14px 0; padding-bottom: 12px; border-bottom: 1px solid var(--line); }
.champion-person .leader-name { margin-bottom: 6px; }
</style>
</head>
<body class="<?= ($_GET['embedded'] ?? '') === '1' ? 'embedded' : '' ?>"><main>
<header class="page-header">
<div class="back"><a href="admin.php" class="btn-back">&laquo; Back to Reports</a></div>
<h1>Agent Leaderboard</h1>
<p id="date" class="note-box">Live agent performance</p>
</header>
<nav class="standings-periods" aria-label="Standings period">
<?php foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month'] as $periodKey => $periodLabel): ?>
<a class="standings-period" href="?<?= escapeHtml(http_build_query(['period' => $periodKey, 'campaign_id' => $filters['campaign_id']])) ?>" <?= $filters['period'] === $periodKey ? 'aria-current="page"' : '' ?>><?= escapeHtml($periodLabel) ?></a>
<?php endforeach; ?>
<?php if ($filters['period'] === 'custom'): ?><span class="standings-period" aria-current="page">Custom Range</span><?php endif; ?>
</nav>
<p class="note-box standings-note">Weekly: Monday through today. Monthly: first day through today. Points use current weights; pause allowances reset daily.</p>
<form method="get" id="filters">
<input type="hidden" name="period" value="custom">
<label for="campaign_id">Campaign: <select id="campaign_id" name="campaign_id">
<option value="--ALL--">-- ALL CAMPAIGNS --</option>
<?php if ($filters['campaign_id'] !== '--ALL--'): ?>
<option value="<?= escapeHtml($filters['campaign_id']) ?>" selected><?= escapeHtml($filters['campaign_id']) ?></option>
<?php endif; ?>
</select></label>
<label for="begin_date">Start Date: <input type="date" id="begin_date" name="begin_date" value="<?= escapeHtml($filters['begin_date']) ?>" required></label>
<label for="end_date">End Date: <input type="date" id="end_date" name="end_date" value="<?= escapeHtml($filters['end_date']) ?>" required></label>

<button type="submit">Generate Scoreboard</button>
<button id="refresh" type="button">Refresh Now</button>
</form>
<?php if ($filterError !== ''): ?><p class="note-box error"><?= escapeHtml($filterError) ?></p><?php endif; ?>
<details class="scoring-panel"><summary>Scoring settings</summary>
<p class="goal-meta">Edit points per action. Zero disables a reward or deduction. Changes recalculate rankings for every selected date, including past dates.</p>
<form id="scoring-form" method="post">
<?php $scoringLabels = ['calls' => 'Connected call (> 2 sec)', 'sales' => 'Sale', 'appointments' => 'Appointment (CALLBK)', 'long_conversations' => 'Long conversation (> 2 min)', 'excessive_pauses' => 'Each excess pause hour']; ?>
<?php foreach ($scoringLabels as $metric => $label): ?>
<label for="weight-<?= escapeHtml($metric) ?>"><?= escapeHtml($label) ?>
<input id="weight-<?= escapeHtml($metric) ?>" name="weights[<?= escapeHtml($metric) ?>]" type="number" step="1" required min="<?= $metric === 'excessive_pauses' ? -10000 : 0 ?>" max="<?= $metric === 'excessive_pauses' ? 0 : 10000 ?>" value="" disabled></label>
<?php endforeach; ?>
<button type="submit" id="scoring-save" disabled>Save Points</button>
</form>
<p id="scoring-message" role="status" aria-live="polite">Saving requires an active admin account with user level 8 or higher.</p>
</details>
<section class="daily-goal" aria-label="Today's team sales goal">
<div class="goal-heading"><div><h2>🎯 Today's sales target: <span id="goal-target">—</span></h2><p class="goal-meta" id="goal-date">Today · All campaigns</p></div><strong id="goal-percent">—</strong></div>
<div class="goal-track" id="goal-progress" role="progressbar" aria-label="Today's sales target progress" aria-valuemin="0" aria-valuemax="100"><div class="goal-fill" id="goal-fill"></div></div>
<p class="goal-meta" id="goal-sales">Loading today's progress…</p>
<form id="target-form" method="post">
<label for="target-input">Daily sales target<input id="target-input" name="target" type="number" min="1" max="1000000" step="1" required value="<?= (int) $config['daily_sales_target'] ?>"></label>
<button type="submit" id="target-save">Save Target</button>
</form>
<p id="target-message" role="status" aria-live="polite">Saved target applies to all viewers of this admin scoreboard and stays in effect until changed.</p>
</section>
<section class="cards" aria-label="Team totals">
<div class="card"><span>Agents in report</span><strong id="agents">—</strong></div>
<div class="card"><span>Total calls</span><strong id="calls">—</strong></div>
<div class="card"><span>Sales</span><strong id="sales">—</strong></div>
<div class="card"><span>Team conversion</span><strong id="conversion">—</strong></div>
</section>
<div class="spotlight-heading"><h2>🏆 Leading the way</h2><span>Top performers · selected dates and campaign</span></div>
<div id="milestone" role="status" aria-live="polite"></div>
<section id="leaders" class="leaders" aria-label="Top performers"><p class="note-box">Your leaders will appear here once performance loads.</p></section>
<h3 id="report-title">Agent Rankings</h3>
<p class="note-box"><em><strong>* Total Calls counts conversations longer than 2 seconds. Sales counts SALE on the call or lead, regardless of talk duration.</strong></em></p>
<section aria-label="Agent leaderboard"><div class="scroll"><table><thead><tr><th scope="col">Rank</th><th scope="col">Agent</th><th scope="col">Total Calls</th><th scope="col">Calls &gt; 2 Min</th><th scope="col">Talk Time</th><th scope="col">Sales</th><th scope="col">Conversion</th><th scope="col">Points</th></tr></thead><tbody id="rows"><tr><td colspan="8" class="empty">Loading agent performance…</td></tr></tbody></table></div></section>
<div style="text-align: center; width: 100%;">
    <button type="button" id="export-rankings" disabled style="margin-top: 16px; background: #00edce; color: black; font-size: 14px; font-weight: 600; padding: 12px 20px; border-radius: 7px;">
        Download CSV
    </button>
</div>
<details class="rules"><summary>How points work</summary>
<?php
$ruleLabels = ['calls' => 'Connected call (> 2 sec)', 
    // 'qualified_leads' => 'Qualified lead',
    'sales' => 'Sale (same count as Sales column)', 
    'appointments' => 'Appointment',
    'long_conversations' => 'Long conversation (> 2 min)', 
    // 'complaints' => 'Customer complaint',
    'excessive_pauses' => 'Each started excess pause hour'];
$enabled = [
    'calls' => true, 'sales' => true, 'long_conversations' => true,
    'qualified_leads' => !empty($config['scoring']['qualified_statuses']),
    'appointments' => !empty($config['scoring']['appointment_statuses']),
    'complaints' => !empty($config['scoring']['complaint_statuses']),
    'excessive_pauses' => true,
];
foreach ($ruleLabels as $metric => $label): ?>
<p><?= escapeHtml($label) ?>: <span data-rule-weight="<?= escapeHtml($metric) ?>"><?= sprintf('%+d', $config['scoring']['weights'][$metric]) ?></span> points<?= $enabled[$metric] ? '' : ' — disabled until configured' ?></p>
<?php endforeach; ?>
<p>Points stack: a connected sale lasting over 2 minutes earns connected-call, sale, and long-conversation points. Duration alone does not measure call quality.</p>
<p>Disposition rewards count matching agent-log rows. All pause reasons count. Each day allows <?= (int) ($config['scoring']['pause_allowance_seconds'] / 60) ?> minutes; each started additional <?= (int) ($config['scoring']['pause_penalty_interval_seconds'] / 60) ?> minutes incurs one deduction. Daily deductions are then added across the selected dates. Pause totals follow the campaign filter and include midnight records. Scores recalculate for the selected dates and campaign.</p>
</details>

<p id="status" class="status" role="status" aria-live="polite">Connecting to VICIdial…</p>
<noscript><p>Enable JavaScript to load and refresh the leaderboard.</p></noscript>
<footer><p>Ranked by points, then sales, conversion, and calls. Conversion = sales ÷ total calls. Talk time is total recorded agent talk time.</p>
<p>Counting rules match Conversation Score. A lead’s current SALE status can affect historical counts; repeated conversations with the same lead can each count.</p>
<p>Reporting range: start date 00:00:01 through end date 23:59:59, matching the rapport report. Updates every <?= (int) $config['refresh_seconds'] ?> seconds.</p></footer>
</main>
<dialog id="agentDetailPopup" aria-labelledby="agentDetailTitle">
    <div class="agent-detail-header">
        <h2 id="agentDetailTitle">Agent details</h2>
        <button type="button" id="agentDetailClose" autofocus>Close &times;</button>
    </div>
    <p id="agentDetailUpdated"></p>
    <dl id="agentDetailFields"></dl>
    <section class="sales-history" aria-labelledby="salesHistoryTitle">
        <h3 id="salesHistoryTitle">Performance · Last 7 Days</h3>
        <div class="history-tabs" role="group" aria-label="Performance metric">
            <button type="button" data-history-metric="sales" aria-pressed="true">Sales</button>
            <button type="button" data-history-metric="points" aria-pressed="false">Points</button>
            <button type="button" data-history-metric="conversion" aria-pressed="false">Conversion</button>
        </div>
        <p id="salesHistoryStatus" role="status" aria-live="polite"></p>
        <div id="salesHistoryChart" role="list" aria-label="Daily sales"></div>
    </section>
</dialog>
<script>
'use strict';
const interval = <?= (int) $config['refresh_seconds'] * 1000 ?>;
const el = id => document.getElementById(id);
const number = value => new Intl.NumberFormat().format(value);
const percent = value => value === null ? '—' : value.toFixed(1) + '%';
const duration = value => [Math.floor(value / 3600), Math.floor(value % 3600 / 60), value % 60].map(n => String(n).padStart(2, '0')).join(':');
const filters = <?= json_encode($filters, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let rankingExport = null;
let busy = false, timer, lastUpdated = '', campaignsLoaded = false;
function cell(row, value, className = '') {
    const td = document.createElement('td');
    td.textContent = value; td.className = className; row.appendChild(td); return td;
}
// Milestones describe recorded outcomes in the selected report range.
const badgeRules = [
    {id: 'first-sale', metric: 'sales', minimum: 1, label: '⭐ First Sale', style: 'sale'},
    {id: 'five-sales', metric: 'sales', minimum: 5, label: '🏆 5 Sales', style: 'sale'},
    {id: 'five-appointments', metric: 'appointments', minimum: 5, label: '📅 5 Appointments', style: ''},
];
let previousAchievements = null;
function earnedBadges(agent) { return badgeRules.filter(rule => agent[rule.metric] >= rule.minimum); }
function badgeList(agent, newlyEarned) {
    const list = document.createElement('div'); list.className = 'badges';
    for (const rule of earnedBadges(agent)) {
        const badge = document.createElement('span');
        badge.className = 'badge ' + rule.style + (newlyEarned.has(agent.user + ':' + rule.id) ? ' earned' : '');
        badge.textContent = rule.label;
        badge.title = 'At least ' + rule.minimum + ' ' + rule.metric + ' in the selected report range';
        list.appendChild(badge);
    }
    return list;
}
const conversionMinimums = <?= json_encode($config['conversion_min_calls']) ?>;
function championWinners(agents, category, minimumCalls) {
    const eligible = agents.filter(agent => category === 'points' ||
        (agent.sales > 0 && (category !== 'conversion' || agent.calls >= minimumCalls)));
    const compare = (a, b) => category === 'conversion'
        ? a.sales * b.calls - b.sales * a.calls
        : category === 'sales' ? a.sales - b.sales : a.score - b.score;
    let winners = [];
    eligible.forEach(agent => {
        if (!winners.length || compare(agent, winners[0]) > 0) winners = [agent];
        else if (compare(agent, winners[0]) === 0) winners.push(agent);
    });
    return winners;
}
function renderLeaders(agents, newlyEarned) {
    const minimum = conversionMinimums[filters.period] ?? conversionMinimums.custom;
    const cards = document.createDocumentFragment();
    [
        {key: 'points', label: '🏆 Points Leader', empty: 'No activity yet'},
        {key: 'sales', label: '💰 Sales Champion', empty: 'No sales yet'},
        {key: 'conversion', label: '🎯 Conversion Champion', empty: 'No qualifying agent yet'}
    ].forEach(category => {
        const winners = championWinners(agents, category.key, minimum);
        const card = document.createElement('article'); card.className = 'leader';
        const title = document.createElement('div'); title.className = 'leader-place'; title.textContent = category.label;
        card.appendChild(title);
        if (winners.length) {
            const first = winners[0];
            const value = document.createElement('strong'); value.className = 'leader-points';
            value.textContent = category.key === 'points' ? number(first.score) : category.key === 'sales'
                ? number(first.sales) : percent(100 * first.sales / first.calls);
            const unit = document.createElement('small'); unit.textContent = category.key === 'points' ? 'points' : category.key === 'sales' ? 'sales' : 'conversion';
            value.appendChild(unit); card.appendChild(value);
            if (winners.length > 1) {
                const tied = document.createElement('p'); tied.className = 'leader-stats'; tied.textContent = 'Joint winners · ' + winners.length + ' agents'; card.appendChild(tied);
            }
            winners.forEach(agent => {
                const person = document.createElement('div'); person.className = 'champion-person';
                const name = document.createElement('h3'); name.className = 'leader-name'; name.textContent = agent.agent + ' · #' + agent.user;
                const stats = document.createElement('p'); stats.className = 'leader-stats';
                stats.textContent = category.key === 'conversion' ? number(agent.sales) + ' sales / ' + number(agent.calls) + ' calls'
                    : number(agent.sales) + ' sales · ' + number(agent.calls) + ' calls';
                person.append(name, stats, badgeList(agent, newlyEarned)); card.appendChild(person);
            });
        } else {
            const empty = document.createElement('p'); empty.className = 'leader-name'; empty.textContent = category.empty; card.appendChild(empty);
        }
        if (category.key === 'conversion') {
            const rule = document.createElement('p'); rule.className = 'leader-stats';
            rule.textContent = 'Requires at least ' + number(minimum) + ' calls and 1 sale in this period.';
            card.appendChild(rule);
        }
        cards.appendChild(card);
    });
    el('leaders').replaceChildren(cards);
}
const mobileAgentView = window.matchMedia('(max-width: 767px)');
const agentDetailPopup = el('agentDetailPopup');
let agentDetailTrigger = null;

let historyController = null;
let historyData = null;
let historyMetric = 'sales';
document.querySelectorAll('[data-history-metric]').forEach(button => {
    button.addEventListener('click', () => {
        historyMetric = button.dataset.historyMetric;
        document.querySelectorAll('[data-history-metric]').forEach(tab => tab.setAttribute('aria-pressed', String(tab === button)));
        if (historyData) renderPerformanceHistory();
    });
});
function renderPerformanceHistory() {
    const data = historyData;
    const values = data.days.map(day => day[historyMetric] ?? 0);
    const positive = Math.max(1, ...values);
    const negative = Math.max(0, ...values.map(value => -value));
    const span = positive + negative;
    const baseline = negative / span * 100;
    const chart = document.createDocumentFragment();
    data.days.forEach((day, index) => {
        const value = day[historyMetric];
        const formatted = historyMetric === 'conversion' ? percent(value) : number(value);
        const column = document.createElement('div'); column.className = 'history-column';
        column.setAttribute('role', 'listitem');
        column.setAttribute('aria-label', day.date + ': ' + formatted + ' ' + historyMetric +
            (historyMetric === 'conversion' ? ', ' + day.sales + ' sales / ' + day.calls + ' calls' : '') + (index === 6 ? ', today so far' : ''));
        const count = document.createElement('span'); count.className = 'history-count'; count.textContent = formatted;
        const track = document.createElement('div'); track.className = 'history-track'; track.setAttribute('aria-hidden', 'true');
        const line = document.createElement('div'); line.className = 'history-baseline'; line.style.bottom = baseline + '%';
        const bar = document.createElement('div'); bar.className = 'history-bar' + (value < 0 ? ' negative' : '');
        const height = Math.abs(value || 0) / span * 100;
        bar.style.height = height + '%'; bar.style.bottom = (value < 0 ? baseline - height : baseline) + '%';
        track.append(line, bar);
        const date = document.createElement('span'); date.className = 'history-date'; date.textContent = index === 6 ? 'Today' : day.date.slice(5);
        column.append(count, track, date);
        if (historyMetric === 'conversion') {
            const ratio = document.createElement('span'); ratio.className = 'history-ratio';
            ratio.textContent = day.sales + ' sales / ' + day.calls + ' calls'; column.appendChild(ratio);
        }
        chart.appendChild(column);
    });
    el('salesHistoryChart').replaceChildren(chart);
    el('salesHistoryChart').setAttribute('aria-label', 'Daily ' + historyMetric);
    const sales = data.days.reduce((sum, day) => sum + day.sales, 0);
    const calls = data.days.reduce((sum, day) => sum + day.calls, 0);
    const points = data.days.reduce((sum, day) => sum + day.points, 0);
    const summary = historyMetric === 'conversion' ? percent(calls ? sales / calls * 100 : null) + ' overall · ' + sales + ' sales / ' + calls + ' calls'
        : historyMetric === 'points' ? number(points) + ' points · Calculated using current scoring weights'
        : number(sales) + ' sales';
    el('salesHistoryStatus').textContent = summary + ' · ' + (data.campaign === '--ALL--' ? 'All campaigns' : data.campaign) +
        '. Today is incomplete. All trends use agent-log SALE only; table totals may differ.';
}
async function loadSalesHistory(agent) {
    if (historyController) historyController.abort();
    const controller = new AbortController(); historyController = controller;
    const timeout = setTimeout(() => controller.abort(), 15000);
    historyData = null;
    el('salesHistoryChart').replaceChildren();
    el('salesHistoryStatus').textContent = 'Loading performance history…';
    try {
        const url = new URL(window.location.href); url.search = '';
        url.searchParams.set('action', 'sales_history'); url.searchParams.set('agent', agent);
        url.searchParams.set('campaign_id', filters.campaign_id);
        const response = await fetch(url, {cache: 'no-store', credentials: 'same-origin', signal: controller.signal});
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Sales history unavailable.');
        if (historyController !== controller || !agentDetailPopup.open) return;
        historyData = data;
        renderPerformanceHistory();
    } catch (error) {
        if (historyController === controller && agentDetailPopup.open) {
            el('salesHistoryStatus').textContent = error.name === 'AbortError' ? 'History request timed out. Reopen the agent to retry.' : error.message;
        }
    } finally { clearTimeout(timeout); }
}
function openAgentDetails(agent, rank, updatedAt) {
    agentDetailTrigger = agent.user;
    el('agentDetailTitle').textContent = agent.agent + ' · #' + agent.user;
    el('agentDetailUpdated').textContent = 'Snapshot updated ' + updatedAt;
    const fields = [
        ['Rank', '#' + rank], ['Points', number(agent.score)],
        ['Total calls', number(agent.calls)],
        ['Calls > 2 min', number(agent.calls_over_two_minutes)],
        ['Talk time', duration(agent.talk_seconds)],
        ['Sales', number(agent.sales)], ['Conversion', percent(agent.conversion)],
        ['Appointments', number(agent.appointments)],
        ['Total pause time', duration(agent.pause_seconds)],
        ['Pause deductions', number(agent.excessive_pauses)]
    ];
    const fragment = document.createDocumentFragment();
    fields.forEach(([label, value]) => {
        const item = document.createElement('div');
        const term = document.createElement('dt');
        const description = document.createElement('dd');
        term.textContent = label; description.textContent = value;
        item.append(term, description); fragment.appendChild(item);
    });
    el('agentDetailFields').replaceChildren(fragment);
    if (!agentDetailPopup.open) agentDetailPopup.showModal();
    loadSalesHistory(agent.user);
}
el('agentDetailClose').addEventListener('click', () => agentDetailPopup.close());
agentDetailPopup.addEventListener('click', event => {
    if (event.target !== agentDetailPopup) return;

    const rect = agentDetailPopup.getBoundingClientRect();

    const clickedOutside =
        event.clientX < rect.left ||
        event.clientX > rect.right ||
        event.clientY < rect.top ||
        event.clientY > rect.bottom;

    if (clickedOutside) {
        agentDetailPopup.close();
    }
});
agentDetailPopup.addEventListener('cancel', event => {
    event.preventDefault(); agentDetailPopup.close();
});
// Handle Escape before the parent dialer's iframe listener sees it.
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && agentDetailPopup.open) {
        event.preventDefault(); event.stopImmediatePropagation();
        agentDetailPopup.close();
    }
}, true);
agentDetailPopup.addEventListener('close', () => {
    if (historyController) historyController.abort();
    historyController = null;
    const currentRow = Array.from(document.querySelectorAll('#rows tr[data-agent-row]'))
        .find(row => row.dataset.agentRow === agentDetailTrigger);
    if (currentRow) currentRow.focus();
});
function updateMobileRowAccess() {
    document.querySelectorAll('#rows tr[data-agent-row]').forEach(row => {
        row.tabIndex = 0; row.setAttribute('aria-haspopup', 'dialog');
    });
}

async function refresh() {
    if (busy) return;
    clearTimeout(timer); busy = true; el('refresh').disabled = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
        const url = new URL(window.location.href); url.search = ''; url.searchParams.set('data', '1');
        if (document.body.classList.contains('embedded')) url.searchParams.set('embedded', '1');
        for (const [key, value] of Object.entries(filters)) url.searchParams.set(key, value);
        const response = await fetch(url, {cache: 'no-store', signal: controller.signal});
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Unable to load leaderboard.');
        const achievements = new Set();
        const newlyEarned = new Set();
        const celebrations = [];
        for (const agent of data.agents) {
            for (const rule of earnedBadges(agent)) {
                const key = agent.user + ':' + rule.id; achievements.add(key);
                if (previousAchievements !== null && !previousAchievements.has(key)) {
                    newlyEarned.add(key); celebrations.push(agent.agent + ' earned ' + rule.label);
                }
            }
        }
        renderLeaders(data.agents, newlyEarned);
        el('milestone').textContent = celebrations.join(' · ');
        previousAchievements = achievements;
        const rows = document.createDocumentFragment();
        data.agents.forEach((agent, index) => {
            const row = document.createElement('tr');
            row.dataset.agentRow = agent.user;
            row.addEventListener('click', event => {
                if (event.target.closest('details, button, a, input')) return;
                openAgentDetails(agent, index + 1, data.updated_at);
            });
            row.addEventListener('keydown', event => {
                if (event.target !== row) return;
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openAgentDetails(agent, index + 1, data.updated_at);
                }
            });
            cell(row, ([ '🥇 ', '🥈 ', '🥉 ' ][index] || '') + (index + 1));
            const name = cell(row, agent.agent, 'name');
            const user = document.createElement('span'); user.className = 'user'; user.textContent = '# ' + agent.user; name.appendChild(user); name.appendChild(badgeList(agent, newlyEarned));
            cell(row, number(agent.calls)); cell(row, number(agent.calls_over_two_minutes)); cell(row, duration(agent.talk_seconds));
            cell(row, number(agent.sales), 'sales'); cell(row, percent(agent.conversion));
            const score = cell(row, number(agent.score), 'score');
            const details = document.createElement('details');
            const summary = document.createElement('summary'); summary.textContent = 'Breakdown'; details.appendChild(summary);
            const labels = {calls: 'Connected calls', qualified_leads: 'Qualified leads', sales: 'Sales', appointments: 'Appointments', long_conversations: 'Long conversations', complaints: 'Complaints', excessive_pauses: 'Excess pause-hour deductions'};
            for (const [metric, points] of Object.entries(agent.score_breakdown)) {
                const line = document.createElement('div');
                line.textContent = labels[metric] + ': ' + number(agent[metric]) + ' → ' + (points > 0 ? '+' : '') + number(points) + ' pts';
                details.appendChild(line);
            }
            const pauseLine = document.createElement('div');
            pauseLine.textContent = 'Total pause time: ' + duration(agent.pause_seconds);
            details.appendChild(pauseLine);
            score.appendChild(details); rows.appendChild(row);
        });
        if (!data.agents.length) {
            const row = document.createElement('tr'); cell(row, 'No agent activity recorded for this date range and campaign.', 'empty').colSpan = 8; rows.appendChild(row);
        }
        el('rows').replaceChildren(rows);
        updateScoringLabels(data.weights);
        rankingExport = {
            agents: data.agents,
            filters: data.filters,
            updatedAt: data.updated_at
        };

        el('export-rankings').disabled = data.agents.length === 0;
        updateMobileRowAccess();
        for (const key of ['agents', 'calls', 'sales']) el(key).textContent = number(data.totals[key]);
        el('conversion').textContent = percent(data.totals.conversion);
        const goal = data.daily_goal;
        el('goal-target').textContent = goal.target > 0 ? number(goal.target) : 'Not configured';
        el('goal-percent').textContent = percent(goal.progress);
        el('goal-date').textContent = goal.date + ' · All campaigns · Independent of report filters';
        const progress = Math.max(0, Math.min(100, goal.progress || 0));
        el('goal-fill').style.width = progress + '%';
        el('goal-progress').setAttribute('aria-valuenow', progress);
        el('goal-progress').setAttribute('aria-valuetext', goal.target > 0 ? goal.sales + ' of ' + goal.target + ' sales' : 'Target not configured');
        el('goal-sales').textContent = number(goal.sales) + ' sales today' + (goal.target > 0 ? ' · ' + (goal.sales >= goal.target ? '🎉 Target reached!' : number(goal.target - goal.sales) + ' to go') : '');
        el('date').textContent = data.filters.begin_date + ' to ' + data.filters.end_date;
        el('report-title').textContent = ({today: 'Daily Standings', week: 'Weekly Standings', month: 'Monthly Standings', custom: 'Agent Rankings'}[data.filters.period]) + ' · ' + (filters.campaign_id === '--ALL--' ? 'All Campaigns' : filters.campaign_id);
        // Populate once so automatic refresh does not overwrite unsubmitted selections.
        if (!campaignsLoaded) {
            const select = el('campaign_id');
            const selected = select.value;
            const options = [new Option('-- ALL CAMPAIGNS --', '--ALL--')];
            data.campaigns.forEach(c => options.push(new Option(c.campaign_id + ' - ' + c.campaign_name, c.campaign_id)));
            if (selected !== '--ALL--' && !data.campaigns.some(c => c.campaign_id === selected)) options.push(new Option(selected, selected));
            select.replaceChildren(...options); select.value = selected; campaignsLoaded = true;
        }
        lastUpdated = data.updated_at;
        el('status').className = 'status'; el('status').textContent = 'Updated ' + lastUpdated;
    } catch (error) {
        el('status').className = 'status error';
        el('status').textContent = (lastUpdated ? 'Data may be stale. Last updated ' + lastUpdated + '. ' : '') + 'Could not refresh. Check database configuration or connectivity; retrying automatically.';
        if (!lastUpdated) {
            const row = document.createElement('tr'); cell(row, 'Leaderboard unavailable — check database settings and server logs.', 'empty').colSpan = 8;
            el('rows').replaceChildren(row);
        }
    } finally {
        clearTimeout(timeout); busy = false; el('refresh').disabled = false;
        timer = setTimeout(refresh, interval);
    }
}
el('filters').addEventListener('submit', event => {
    el('end_date').setCustomValidity('');
    if (el('begin_date').value > el('end_date').value) {
        event.preventDefault();
        el('end_date').setCustomValidity('End date must be on or after start date.');
        el('end_date').reportValidity();
    }
});
for (const id of ['begin_date', 'end_date']) el(id).addEventListener('input', () => el('end_date').setCustomValidity(''));
let scoringFormDirty = false;
let scoringLoaded = false;
el('scoring-form').addEventListener('input', () => { scoringFormDirty = true; });
function updateScoringLabels(weights) {
    if (!scoringFormDirty) {
        for (const [metric, value] of Object.entries(weights)) {
            const input = el('weight-' + metric);
            if (input) { input.value = value; input.disabled = false; }
        }
    }
    if (!scoringLoaded) { el('scoring-save').disabled = false; scoringLoaded = true; }
    document.querySelectorAll('[data-rule-weight]').forEach(node => {
        const value = weights[node.dataset.ruleWeight];
        node.textContent = (value >= 0 ? '+' : '') + value;
    });
}
el('scoring-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (!el('scoring-form').reportValidity()) return;
    const button = el('scoring-save'); button.disabled = true;
    el('scoring-message').textContent = 'Saving points…';
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
        const body = new URLSearchParams(new FormData(el('scoring-form')));
        body.set('csrf', <?= json_encode($targetCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
        const url = new URL(window.location.href); url.search = '?save_weights=1';
        const response = await fetch(url, {method: 'POST', credentials: 'same-origin', body, signal: controller.signal});
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Could not save points.');
        scoringFormDirty = false;
        el('scoring-message').textContent = 'Points saved. Rankings will update on the next refresh.';
        if (!busy) refresh();
    } catch (error) {
        el('scoring-message').textContent = error.name === 'AbortError' ? 'Request timed out. Reload to check whether points were saved.' : error.message;
    } finally { clearTimeout(timeout); button.disabled = false; }
});
el('target-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (!el('target-form').reportValidity()) return;
    el('target-save').disabled = true;
    el('target-message').textContent = 'Saving target…';
    try {
        const url = new URL(window.location.href); url.search = '?save_target=1';
        const body = new URLSearchParams({target: el('target-input').value,
            csrf: <?= json_encode($targetCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>});
        const response = await fetch(url, {method: 'POST', credentials: 'same-origin', body});
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Could not save target.');
        el('target-message').textContent = 'Target saved: ' + number(result.target) + ' sales per day.';
        // An in-flight refresh will finish shortly; polling also picks up the saved value.
        if (!busy) refresh();
    } catch (error) {
        el('target-message').textContent = error.message;
    } finally { el('target-save').disabled = false; }
});
el('refresh').addEventListener('click', refresh);

function csvCell(value) {
    let text = String(value ?? '');

    // Prevent spreadsheet formulas in names, usernames, or other text.
    if (typeof value === 'string' &&
        /^[\s]*[=+\-@]/.test(text)) {
        text = "'" + text;
    }

    return '"' + text.replace(/"/g, '""') + '"';
}

el('export-rankings').addEventListener('click', () => {
    if (!rankingExport || !rankingExport.agents.length) return;

    const snapshot = rankingExport;

    const rows = [
        [
            'Rank',
            'Agent ID',
            'Agent Name',
            'Total Calls',
            'Calls > 2 Min',
            'Talk Time',
            'Sales',
            'Conversion (%)',
            'Points',
            'Pause Time',
            'Campaign',
        ],
        ...snapshot.agents.map((agent, index) => [
            index + 1,
            agent.user,
            agent.agent,
            agent.calls,
            agent.calls_over_two_minutes,
            duration(agent.talk_seconds),
            agent.sales,
            agent.conversion === null
                ? ''
                : agent.conversion.toFixed(1),
            agent.score,
            duration(agent.pause_seconds),
            snapshot.filters.campaign_id === '--ALL--'
                ? 'All campaigns'
                : snapshot.filters.campaign_id
        ])
    ];

    const csv = rows
        .map(row => row.map(csvCell).join(','))
        .join('\r\n');

    // UTF-8 marker helps Excel display agent names correctly.
    const blob = new Blob(['\uFEFF', csv], {
        type: 'text/csv;charset=utf-8;'
    });

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download =
        'agent-rankings_' +
        snapshot.filters.begin_date + '_to_' +
        snapshot.filters.end_date + '.csv';

    document.body.appendChild(link);
    link.click();
    link.remove();

    setTimeout(() => URL.revokeObjectURL(url), 1000);
});

refresh();
</script></body></html>
