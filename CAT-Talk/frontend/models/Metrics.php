<?php

require_once __DIR__ . '/../utilities/db.php';
require_once __DIR__ . '/../utilities/UUID.php';

class Metrics implements JsonSerializable {
    protected $taskId;
    protected $userId; // Add userId property
    protected $details;

    public function __construct() { }

    public static function withTaskId(string $taskId): ?Metrics {
        $stmt = PostgresDB::run(
            "SELECT * FROM metrics WHERE task_id = :taskId",
            ['taskId' => $taskId]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $instance = new self();
            $instance->fill($row);
            return $instance;
        }
        return null;
    }

    protected function fill($row): void {
        $this->taskId = $row['task_id'];
        $this->userId = isset($row['user_id']) ? $row['user_id'] : (isset($this->details['user_id']) ? $this->details['user_id'] : null);
        $this->details = is_string($row['details']) ? json_decode($row['details'], true) : $row['details'];
        // If user_id is missing in the column but present in details, set it
        if (!$this->userId && isset($this->details['user_id'])) {
            $this->userId = $this->details['user_id'];
        }
    }

    public function save() {
        $exists = self::withTaskId($this->taskId);
        $user_id = $this->userId ?? (isset($this->details['user_id']) ? $this->details['user_id'] : null);
        // Always keep user_id in details for backward compatibility

        if ($exists) {
            PostgresDB::run(
                "UPDATE metrics SET user_id = ?, details = ? WHERE task_id = ?",
                [$user_id, json_encode($this->details), $this->taskId]
            );
        } else {
            PostgresDB::run(
                "INSERT INTO metrics (task_id, user_id, details) VALUES (?, ?, ?)",
                [$this->taskId, $user_id, json_encode($this->details)]
            );
        }
        return $this;
    }

    public function createWithType($taskId, $type, $parameters) {
        $this->taskId = $taskId;
        $details = [];
        if ($type === 'transcribe') {
            $details = [
                "type" => "transcribe",
                "minutes_audio" => isset($parameters['minutes_audio']) ? floatval($parameters['minutes_audio']) : 0.0,
                "open_ai_whisper_equiv_cost" => isset($parameters['open_ai_whisper_equiv_cost']) ? floatval($parameters['open_ai_whisper_equiv_cost']) : 0.0,
                "words_transcribed" => isset($parameters['words_transcribed']) ? intval($parameters['words_transcribed']) : 0
            ];
        } elseif ($type === 'synchronify') {
            $details = [
                "type" => "synchronify",
                "minutes_audio" => isset($parameters['minutes_audio']) ? floatval($parameters['minutes_audio']) : 0.0,
                "open_ai_whisper_equiv_cost" => isset($parameters['open_ai_whisper_equiv_cost']) ? floatval($parameters['open_ai_whisper_equiv_cost']) : 0.0,
                "tokens_in" => isset($parameters['tokens_in']) ? intval($parameters['tokens_in']) : 0,
                "tokens_out" => isset($parameters['tokens_out']) ? intval($parameters['tokens_out']) : 0,
                "open_ai_llm_equiv_cost" => isset($parameters['open_ai_llm_equiv_cost']) ? floatval($parameters['open_ai_llm_equiv_cost']) : 0.0
            ];
        } elseif ($type === 'llm') {
            $details = [
                "type" => "llm",
                "tokens_in" => isset($parameters['tokens_in']) ? intval($parameters['tokens_in']) : 0,
                "tokens_out" => isset($parameters['tokens_out']) ? intval($parameters['tokens_out']) : 0,
                "open_ai_llm_equiv_cost" => isset($parameters['open_ai_llm_equiv_cost']) ? floatval($parameters['open_ai_llm_equiv_cost']) : 0.0
            ];
        }
        // Always set user_id if present in parameters
        if (isset($parameters['user_id'])) {
            $this->userId = $parameters['user_id'];
        }
        $this->setDetails($details);
        $this->save();
        return $this;
    }

    /**
     * Update details for an existing Metrics object based on job type and parameters.
     * This does not create a new Metrics row, only updates details and saves.
     * @param string $jobType
     * @param array $parameters
     * @return void
     */
    public function updateDetailsWithType($jobType, $parameters) {
        $details = [];
        if ($jobType === 'transcribe') {
            $details = [
                "type" => "transcribe",
                "minutes_audio" => isset($parameters['minutes_audio']) ? floatval($parameters['minutes_audio']) : 0.0,
                "words_transcribed" => isset($parameters['words_transcribed']) ? intval($parameters['words_transcribed']) : 0
            ];
        } elseif ($jobType === 'synchronify') {
            $details = [
                "type" => "synchronify",
                "minutes_audio" => isset($parameters['minutes_audio']) ? floatval($parameters['minutes_audio']) : 0.0,
                "tokens_in" => isset($parameters['tokens_in']) ? intval($parameters['tokens_in']) : 0,
                "tokens_out" => isset($parameters['tokens_out']) ? intval($parameters['tokens_out']) : 0
            ];
        } elseif ($jobType === 'llm') {
            $details = [
                "type" => "llm",
                "tokens_in" => isset($parameters['tokens_in']) ? intval($parameters['tokens_in']) : 0,
                "tokens_out" => isset($parameters['tokens_out']) ? intval($parameters['tokens_out']) : 0
            ];
        }
        // Always calculate and add OpenAI costs
        $costs = self::calculateOpenAICosts(array_merge(["type" => $jobType], $details));
        $details = array_merge($details, $costs);
        // Always set user_id if present in parameters
        if (isset($parameters['user_id'])) {
            $details['user_id'] = $parameters['user_id'];
            $this->userId = $parameters['user_id'];
        }
        $this->setDetails($details);
        $this->save();
    }

    /**
     * Calculate OpenAI equivalent API costs for Whisper and LLM models.
     * @param array $params Should include keys: 'minutes_audio', 'tokens_in', 'tokens_out', and 'type' (transcribe, synchronify, llm)
     * @return array Associative array with cost fields filled in.
     */
    public static function calculateOpenAICosts(array $params): array {
        // 2024 OpenAI pricing (as of June 2024):
        // Whisper: $0.006 per minute
        // GPT-4o: $5.00 per 1M input tokens, $15.00 per 1M output tokens
        $WHISPER_COST_PER_MIN = 0.006;
        $LLM_COST_PER_TOKEN_IN = 0.005 / 1000; // $5.00 per 1M tokens = $0.005 per 1K tokens
        $LLM_COST_PER_TOKEN_OUT = 0.015 / 1000; // $15.00 per 1M tokens = $0.015 per 1K tokens

        $type = $params['type'] ?? null;
        $minutes_audio = isset($params['minutes_audio']) ? floatval($params['minutes_audio']) : 0.0;
        $tokens_in = isset($params['tokens_in']) ? intval($params['tokens_in']) : 0;
        $tokens_out = isset($params['tokens_out']) ? intval($params['tokens_out']) : 0;

        $costs = [];
        if ($type === 'transcribe') {
            $costs['open_ai_whisper_equiv_cost'] = round($minutes_audio * $WHISPER_COST_PER_MIN, 6);
        } elseif ($type === 'synchronify') {
            $costs['open_ai_whisper_equiv_cost'] = round($minutes_audio * $WHISPER_COST_PER_MIN, 6);
            $costs['open_ai_llm_equiv_cost'] = round($tokens_in * $LLM_COST_PER_TOKEN_IN + $tokens_out * $LLM_COST_PER_TOKEN_OUT, 6);
        } elseif ($type === 'llm') {
            $costs['open_ai_llm_equiv_cost'] = round($tokens_in * $LLM_COST_PER_TOKEN_IN + $tokens_out * $LLM_COST_PER_TOKEN_OUT, 6);
        }
        return $costs;
    }

    /**
     * Returns all metrics rows formatted for DataTables (array of ['jobId' => ..., 'details' => ...])
     * @return array
     */
    public static function listUsageForDatatable($requestType, $start, $length, $order_by, $order_dir, $filter, $start_date = null, $end_date = null) {
        $query = "SELECT * FROM metrics WHERE 1=1";
        $params = array();

        if (!empty($requestType)) {
            $query .= " AND details->>'type' = :requestType";
            $params['requestType'] = $requestType;
        }
        if (!empty($filter)) {
            $query .= " AND (task_id ILIKE :filter OR user_id ILIKE :filter OR details::text ILIKE :filter)";
            $params['filter'] = "%$filter%";
        }
        if (!empty($start_date)) {
            $query .= " AND created_at >= :start_date";
            $params['start_date'] = $start_date;
        }
        if (!empty($end_date)) {
            $query .= " AND created_at <= :end_date";
            $params['end_date'] = $end_date;
        }
        if (!empty($order_by)) {
            $order_by = preg_replace('/[^a-zA-Z0-9_]/', '', $order_by); // basic SQL injection protection
            $query .= " ORDER BY $order_by $order_dir";
        } else {
            $query .= " ORDER BY created_at DESC";
        }
        if ($length > 0) {
            $query .= " OFFSET :start ROWS FETCH NEXT :length ROWS ONLY";
            $params['start'] = $start;
            $params['length'] = $length;
        }
        $stmt = PostgresDB::prepare($query);
        foreach ($params as $key => $val) {
            if ($key === 'start' || $key === 'length') {
                $stmt->bindValue(":$key", $val, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(":$key", $val);
            }
        }
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $results;
    }

    /**
     * Returns the total count of metrics rows for DataTables.
     * @return int
     */
    public static function countUsageForDatatable(): int {
        $stmt = PostgresDB::run("SELECT COUNT(*) as count FROM metrics");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? intval($row['count']) : 0;
    }

    /**
     * Returns the count of metrics rows matching a filter for DataTables.
     * @param string $filter
     * @return int
     */
    public static function countUsageFilteredForDatatable($filter = ''): int {
        if ($filter && strlen($filter) > 0) {
            $stmt = PostgresDB::run(
                "SELECT COUNT(*) as count FROM metrics WHERE task_id ILIKE :filter OR details ILIKE :filter",
                ["filter" => "%$filter%"]
            );
        } else {
            $stmt = PostgresDB::run("SELECT COUNT(*) as count FROM metrics");
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? intval($row['count']) : 0;
    }

    /**
     * Returns a breakdown of metrics by user and job category for DataTables.
     * Each row: userId, job_type, total_minutes_audio, total_tokens_in, total_tokens_out, total_whisper_cost, total_llm_cost
     * @return array
     */
    public static function listUsageBreakdownByUserAndCategory(): array {
        $stmt = PostgresDB::run(
            "SELECT
                user_id,
                details->>'type' AS job_type,
                SUM(COALESCE((details->>'minutes_audio')::float, 0)) AS total_minutes_audio,
                SUM(COALESCE((details->>'tokens_in')::int, 0)) AS total_tokens_in,
                SUM(COALESCE((details->>'tokens_out')::int, 0)) AS total_tokens_out,
                SUM(COALESCE((details->>'open_ai_whisper_equiv_cost')::float, 0)) AS total_whisper_cost,
                SUM(COALESCE((details->>'open_ai_llm_equiv_cost')::float, 0)) AS total_llm_cost
            FROM metrics
            GROUP BY user_id, job_type
            ORDER BY user_id, job_type"
        );
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = [
                'user_id' => $row['user_id'],
                'job_type' => $row['job_type'],
                'total_minutes_audio' => (float)$row['total_minutes_audio'],
                'total_tokens_in' => (int)$row['total_tokens_in'],
                'total_tokens_out' => (int)$row['total_tokens_out'],
                'total_whisper_cost' => (float)$row['total_whisper_cost'],
                'total_llm_cost' => (float)$row['total_llm_cost'],
            ];
        }
        return $results;
    }

    /**
     * Returns a breakdown of metrics by user, combining all job types, and including user name and id.
     * Each row: user_id, full_name, total_minutes_audio, total_tokens_in, total_tokens_out, total_whisper_cost, total_llm_cost
     * @return array
     */
    public static function listUsageBreakdownByUser($start_date = null, $end_date = null): array {
        $query = "SELECT
                user_id,
                SUM(COALESCE((details->>'minutes_audio')::float, 0)) AS total_minutes_audio,
                SUM(COALESCE((details->>'tokens_in')::int, 0)) AS total_tokens_in,
                SUM(COALESCE((details->>'tokens_out')::int, 0)) AS total_tokens_out,
                SUM(COALESCE((details->>'open_ai_whisper_equiv_cost')::float, 0)) AS total_whisper_cost,
                SUM(COALESCE((details->>'open_ai_llm_equiv_cost')::float, 0)) AS total_llm_cost,
                SUM(COALESCE((details->>'words_transcribed')::int, 0)) AS total_words_transcribed
            FROM metrics
            WHERE 1=1";
        $params = array();
        if (!empty($start_date)) {
            $query .= " AND created_at >= :start_date";
            $params['start_date'] = $start_date;
        }
        if (!empty($end_date)) {
            $query .= " AND created_at <= :end_date";
            $params['end_date'] = $end_date;
        }
        $query .= " GROUP BY user_id ORDER BY user_id";
        $stmt = PostgresDB::prepare($query);
        foreach ($params as $key => $val) {
            $stmt->bindValue(":$key", $val);
        }
        $stmt->execute();
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $user_id = $row['user_id'];
            $full_name = null;
            if ($user_id) {
                $user = User::withId($user_id);
                $full_name = $user ? $user->getFullName() : null;
            }
            $results[] = [
                'user_id' => $user_id,
                'full_name' => $full_name,
                'total_minutes_audio' => (float)$row['total_minutes_audio'],
                'total_tokens_in' => (int)$row['total_tokens_in'],
                'total_tokens_out' => (int)$row['total_tokens_out'],
                'total_whisper_cost' => (float)$row['total_whisper_cost'],
                'total_llm_cost' => (float)$row['total_llm_cost'],
                'total_words_transcribed' => (int)$row['total_words_transcribed'],
            ];
        }
        return $results;
    }

    public static function listUsageBreakdownByTenant($tenantId, $start_date = null, $end_date = null): array {
        $query = "SELECT
                m.user_id,
                SUM(COALESCE((m.details->>'minutes_audio')::float, 0)) AS total_minutes_audio,
                SUM(COALESCE((m.details->>'tokens_in')::int, 0)) AS total_tokens_in,
                SUM(COALESCE((m.details->>'tokens_out')::int, 0)) AS total_tokens_out,
                SUM(COALESCE((m.details->>'open_ai_whisper_equiv_cost')::float, 0)) AS total_whisper_cost,
                SUM(COALESCE((m.details->>'open_ai_llm_equiv_cost')::float, 0)) AS total_llm_cost,
                SUM(COALESCE((m.details->>'words_transcribed')::int, 0)) AS total_words_transcribed
            FROM metrics m
            INNER JOIN tenant_users tu ON m.user_id = tu.user_id
            WHERE tu.tenant_id = :tenantId";
        $params = ['tenantId' => $tenantId];
        if (!empty($start_date)) {
            $query .= " AND m.created_at >= :start_date";
            $params['start_date'] = $start_date;
        }
        if (!empty($end_date)) {
            $query .= " AND m.created_at <= :end_date";
            $params['end_date'] = $end_date;
        }
        $query .= " GROUP BY m.user_id ORDER BY m.user_id";
        $stmt = PostgresDB::prepare($query);
        foreach ($params as $key => $val) {
            $stmt->bindValue(":$key", $val);
        }
        $stmt->execute();
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $user_id = $row['user_id'];
            $full_name = null;
            if ($user_id) {
                $user = User::withId($user_id);
                if ($user) {
                    $full_name = $user->getFullName();
                }
            }
            $results[] = [
                'user_id' => $user_id,
                'full_name' => $full_name,
                'total_minutes_audio' => (float)$row['total_minutes_audio'],
                'total_tokens_in' => (int)$row['total_tokens_in'],
                'total_tokens_out' => (int)$row['total_tokens_out'],
                'total_whisper_cost' => (float)$row['total_whisper_cost'],
                'total_llm_cost' => (float)$row['total_llm_cost'],
                'total_words_transcribed' => (int)$row['total_words_transcribed'],
            ];
        }
        return $results;
    }

    public static function withRow( $row ): Metrics {
        $instance = new self();
        $instance->fill( $row );
        return $instance;
    }

    public static function loadByAddedDate( string $date, string $direction ): array {
        if (is_null($date) || empty($date))
            throw new Exception('No date provided.');

        $metrics = [];
        if ($direction == 'after') {
            $direction = '>';
        } else {
            $direction = '<';
        }
        $stmt = PostgresDB::run(
            "SELECT * FROM metrics WHERE created_at $direction :date ORDER BY created_at",
            ['date' => $date]
        );

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
            $metrics[] = Metrics::withRow($row);
        return $metrics;
    }
    
    public function getTaskId() {
        return $this->taskId;
    }
    public function setTaskId($taskId) {
        $this->taskId = $taskId;
    }
    public function getUserId() {
        return $this->userId;
    }
    public function setUserId($userId) {
        $this->userId = $userId;
    }
    public function getDetails() {
        return $this->details;
    }
    public function setDetails($details) {
        $this->details = $details;
    }

    public function jsonSerialize(): array {
        return [
            'taskId' => $this->taskId,
            'userId' => $this->userId,
            'details' => $this->details
        ];
    }
}

?>
