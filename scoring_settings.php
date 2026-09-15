<?php
declare(strict_types=1);

function validateScoringWeights($input): array
{
    $keys = ['calls', 'sales', 'appointments', 'long_conversations', 'excessive_pauses'];
    if (!is_array($input) || count($input) !== count($keys)) {
        throw new InvalidArgumentException('Provide all five point weights.');
    }
    $weights = [];
    foreach ($keys as $key) {
        $value = $input[$key] ?? null;
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A-?\d+\z/', (string) $value)) {
            throw new InvalidArgumentException('Point weights must be whole numbers.');
        }
        $min = $key === 'excessive_pauses' ? -10000 : 0;
        $max = $key === 'excessive_pauses' ? 0 : 10000;
        $weight = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($weight === false) {
            throw new InvalidArgumentException('Rewards must be 0–10,000; pause deductions must be -10,000–0.');
        }
        $weights[$key] = $weight;
    }
    return $weights;
}

/** Use the database already selected by dbconnect_mysqli.php (Asterisk). */
function scoringTable(string $table): string
{
    if (!in_array($table, ['scoring_settings', 'scoring_settings_history'], true)) {
        throw new InvalidArgumentException('Invalid scoring table.');
    }
    return '`' . $table . '`';
}

function loadScoringWeights(mysqli $link): array
{
    $result = $link->query('SELECT calls, sales, appointments, long_conversations, excessive_pauses FROM '
        . scoringTable('scoring_settings') . ' WHERE id = 1');
    if (!$result) throw new RuntimeException('Cannot read scoreboard scoring settings: ' . $link->error);
    $row = $result->fetch_assoc();
    $result->free();
    if (!$row) throw new RuntimeException('Scoring settings missing. Run scoreboard_setup.sql.');
    return validateScoringWeights($row);
}

function saveScoringWeights(mysqli $link, array $weights, string $admin): void
{
    $weights = validateScoringWeights($weights);
    if (!$link->begin_transaction()) throw new RuntimeException('Cannot start scoring settings transaction.');
    $stmt = null;
    try {
        // A single row update locks the settings and serializes concurrent saves.
        $stmt = $link->prepare('UPDATE ' . scoringTable('scoring_settings') . '
            SET calls=?, sales=?, appointments=?, long_conversations=?, excessive_pauses=?,
                updated_by=?, revision=revision+1, updated_at=UTC_TIMESTAMP() WHERE id=1');
        if (!$stmt) throw new RuntimeException('Cannot prepare scoring update: ' . $link->error);
        $stmt->bind_param('iiiiis', $weights['calls'], $weights['sales'], $weights['appointments'],
            $weights['long_conversations'], $weights['excessive_pauses'], $admin);
        if (!$stmt->execute()) throw new RuntimeException('Cannot update scoring settings: ' . $stmt->error);
        if ($stmt->affected_rows !== 1) throw new RuntimeException('Scoring settings missing. Run scoreboard_setup.sql.');
        $history = $link->query('INSERT INTO ' . scoringTable('scoring_settings_history') . '
            (revision,calls,sales,appointments,long_conversations,excessive_pauses,updated_by,updated_at)
            SELECT revision,calls,sales,appointments,long_conversations,excessive_pauses,updated_by,updated_at
            FROM ' . scoringTable('scoring_settings') . ' WHERE id=1');
        if (!$history) throw new RuntimeException('Cannot record scoring history: ' . $link->error);
        if (!$link->commit()) throw new RuntimeException('Cannot commit scoring settings.');
    } catch (Throwable $error) {
        $link->rollback();
        throw $error;
    } finally {
        if ($stmt) $stmt->close();
    }
}
