<?php
declare(strict_types=1);

$config = [
    'timezone' => getenv('VICIDIAL_TIMEZONE') ?: date_default_timezone_get(),
    'refresh_seconds' => 10,
    // Set your actual daily team target; 500 is the initial example target.
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
    if (($input['embedded'] ?? '') === '1') {
        $input['begin_date'] = $today;
        $input['end_date'] = $today;
        $input['campaign_id'] = '--ALL--';
    }
    $filters = [];
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
        'agents' => $agents, 'campaigns' => $campaigns,
        'filters' => $filters, 'timezone' => $config['timezone'],
        'updated_at' => $now->format('Y-m-d H:i:s T'),
        'totals' => ['calls' => $calls, 'sales' => $sales, 'agents' => count($agents),
            'conversion' => $calls ? round(100 * $sales / $calls, 1) : null],
    ];
}

// When included by the leaderboard page, expose only settings and helpers.
// Authentication and JSON output run only for direct requests to this file.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
session_start(['use_strict_mode' => 1, 'cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
$identity = $_SESSION['leaderboard_agent'] ?? null;
session_write_close();
if (!is_array($identity) || empty($identity['user']) || ($identity['expires'] ?? 0) < time()) {
    http_response_code(401);
    echo json_encode(['error' => 'Agent login required']);
    exit;
}
try {
    $version = '2.14-692c';
    $build = '230927-2036';
    $DB = 0;
    $mel = 1;
    $mysql_log_count = 103;
    $one_mysql_log = 0;
    $php_script = 'vicidial.php';
    $conf_table = 'vicidial_conferences';
    ob_start();
    try {
        require_once __DIR__ . '/dbconnect_mysqli.php';
    } finally {
        ob_end_clean();
    }
    if (!isset($link) || !($link instanceof mysqli) || $link->connect_errno) {
        throw new RuntimeException('Database connection unavailable');
    }
    $link->set_charset('utf8mb4');
    // Stop disclosing a rank when the agent is no longer logged into the dialer.
    $live = selectRows($link, 'SELECT user FROM vicidial_live_agents WHERE user = ? LIMIT 1', [$identity['user']]);
    if (!$live) {
        http_response_code(401);
        echo json_encode(['error' => 'Active dialer login required']);
        exit;
    }
    $filters = reportFilters($config, []);
    // Optional shared APCu cache: each worker serves the same 30-second snapshot.
    $config['scoring']['weights'] = loadScoringWeights($link);
    $key = 'leaderboard-ranks:' . hash('sha256', __DIR__ . json_encode([$config, $filters]));
    $cached = false;
    $canCache = function_exists('apcu_enabled') && apcu_enabled();
    $data = $canCache ? apcu_fetch($key, $cached) : null;
    if (!$cached) {
        $data = snapshot($config, $link, $filters);
        if ($canCache) apcu_store($key, $data, 30);
    }
    $rank = null;
    foreach ($data['agents'] as $index => $agent) {
        if ($agent['user'] === $identity['user']) {
            $rank = $index + 1;
            break;
        }
    }
    echo json_encode(['rank' => $rank, 'updated_at' => $data['updated_at']], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Personal leaderboard rank: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Rank unavailable']);
}
