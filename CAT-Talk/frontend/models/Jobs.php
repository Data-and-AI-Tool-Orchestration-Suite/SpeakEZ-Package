<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

require_once __DIR__ . '/../utilities/db.php';
require_once __DIR__ . '/../utilities/UUID.php';

class Jobs implements JsonSerializable {
    protected $jobId;
    protected $fileId;
    protected $jobName;
    protected $systemPrompt;
    protected $userPrompt;
    protected $outputFormat;
    protected $combine;
    protected $createdAt;
    protected $taskId;

    public function __construct() { }


    public function create($fileId, $jobName, $systemPrompt, $userPrompt, $taskId, $outputFormat = "", $combine=false) {
        $exists = Jobs::withNameAndFile($jobName, $fileId);
        if (is_null($exists)) {
            $this->jobId = UUID::v4();
            $this->fileId = $fileId;
            $this->jobName = $jobName;
            $this->systemPrompt = $systemPrompt;
            $this->userPrompt = $userPrompt;
            $this->outputFormat = $outputFormat;
            $this->combine = (bool) $combine;
            $this->taskId = $taskId;
            
            $now = new DateTime('now', new DateTimeZone('UTC'));
            $this->createdAt = $now->format('Y-m-d H:i:s');
            $this->save();
            return $this;
        } else {
            $exists->systemPrompt = $systemPrompt;
            $exists->userPrompt = $userPrompt;
            $exists->outputFormat = $outputFormat;
            $exists->combine = (bool) $combine;
            $exists->taskId = $taskId;
            
            $now = new DateTime('now', new DateTimeZone('UTC'));
            $exists->createdAt = $now->format('Y-m-d H:i:s');
            $exists->save();
            return $exists;
        }
    }

    public static function getJobs($fileId) {
        $rows = PostgresDB::run(
            "SELECT job_name FROM jobs WHERE file_id = ? AND job_name NOT IN ('verbatimizer', 'describalizer')", 
            [$fileId]
        )->fetchAll(PDO::FETCH_COLUMN);
        return $rows;
    }
    
    public static function withProjectCollectionFileAndName(string $projectId, string $collectionCode, string $fileId, string $jobName): ?Jobs {
        try {
            $instance = new self();
            $instance->loadByProjectCollectionFileAndName($projectId, $collectionCode, $fileId, $jobName);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    protected function loadByProjectCollectionFileAndName(string $projectId, string $collectionCode, string $fileId, string $jobName): void {
        if (empty($projectId) || empty($collectionCode) || empty($jobName) || empty($fileId))
            throw new PDOException('You must supply projectId, collectionCode, jobName, and fileId to load');
        
        $stmt = PostgresDB::run(
            "SELECT jobs.* 
             FROM jobs
             JOIN files ON jobs.file_id = files.id
             JOIN collection_codes ON files.collection_code_id = collection_codes.id
             WHERE jobs.job_name = :jobName
             AND collection_codes.project_id = :projectId
             AND collection_codes.code = :collectionCode
             AND jobs.file_id = :fileId",
            [
                'jobName' => $jobName, 
                'projectId' => $projectId, 
                'collectionCode' => $collectionCode,
                'fileId' => $fileId
            ]
        );
    
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row !== null)
            $this->fill($row);
        else
            throw new PDOException("Job with name [{$jobName}], collection code [{$collectionCode}], project id [{$projectId}], and file id [{$fileId}] not found");
    }

    public static function getDefaultVals(string $job) {
        if (empty($job)) {
            throw new PDOException('You must supply job');
        }
        $stmt = PostgresDB::run (
            "SELECT system_prompt, user_prompt, output_format, combine
            FROM job_defaults
            WHERE job_name = :job",
            [
                'job' => $job
            ]
        );
        
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        return $row;
    }

    public static function setDefaultVals(string $job, string $systemPrompt, string $userPrompt, string $outputFormat="", bool $combine=false) {
        if (empty($job) || empty($systemPrompt) || empty($userPrompt)) {
            throw new PDOException('All parameters must be supplied');
        }
    
        PostgresDB::run(
            "INSERT INTO job_defaults (job_name, system_prompt, user_prompt, output_format, combine)
            VALUES (:job, :system_prompt, :user_prompt, :output_format, :combine)
            ON CONFLICT (job_name) 
            DO UPDATE SET 
                system_prompt = EXCLUDED.system_prompt,
                user_prompt = EXCLUDED.user_prompt,
                output_format = EXCLUDED.output_format,
                combine = EXCLUDED.combine",
            [
                'job' => $job,
                'system_prompt' => $systemPrompt,
                'user_prompt' => $userPrompt,
                'output_format' => $outputFormat,
                'combine' => $combine ? 'true' : 'false'
            ]
        );
    }
    


    public static function withId(string $id): ?Jobs {
        try {
            $instance = new self();
            $instance->loadById($id);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    protected function loadById(string $id): void {
        if (empty($id))
            throw new PDOException('You must supply an id to load');

        $stmt = PostgresDB::run(
            "SELECT * FROM jobs WHERE job_id = :id",
            ['id' => $id]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row <> null)
            $this->fill( $row );
        else
            throw new PDOException("Job with id [{$id}] not found");

    }

    public static function withNameAndFile(string $jobName, string $fileId): ?Jobs {
        try {
            $instance = new self();
            $instance->loadByNameandFile($jobName, $fileId);
            return $instance;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return null;
        }
    }

    protected function loadByNameandFile(string $jobName, string $fileId): void {
        if (!$jobName || empty($jobName))
            throw new PDOException('You must supply a jobName to load');

        $stmt = PostgresDB::run(
            "SELECT * FROM public.jobs
            WHERE job_name = :jobName
            AND file_id = :fileId",
            ["jobName" => $jobName, "fileId" => $fileId]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row <> null) {
            $this->fill($row);
        }
        else
            throw new PDOException("Job with job name $jobName not found");
    }

    protected function fill($row): void {
        $this->jobId = $row['job_id'];
        $this->fileId = $row['file_id'];
        $this->jobName = $row['job_name'];
        $this->systemPrompt = $row['system_prompt'];
        $this->userPrompt = $row['user_prompt'];
        $this->outputFormat = $row['output_format'];
        $this->combine = (bool) $row['combine'];
        $this->createdAt = $row['created_at'];
        $this->taskId = $row['task_id'];
    }    

    public function save() {
        error_log("info: ".print_r($this->jsonSerialize(), true));
        error_log($this->combine);
        $exists = Jobs::withNameAndFile($this->jobName, $this->fileId);
        if (!is_null($exists)) {
            error_log("existing");
            // Update existing job
            PostgresDB::run("UPDATE jobs SET file_id = ?, job_name = ?, system_prompt = ?, user_prompt = ?, output_format = ?, combine = ?, task_id = ? WHERE job_id = ?",
                [$this->fileId, $this->jobName, $this->systemPrompt, $this->userPrompt, $this->outputFormat, $this->combine ? 'true' : 'false', $this->taskId, $this->jobId]);
            return Files::withId($exists->jobId);
        } else {
            error_log("non-existing");
            // Insert new job
            PostgresDB::run("INSERT INTO jobs (job_id, file_id, job_name, system_prompt, user_prompt, output_format, combine, task_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$this->jobId, $this->fileId, $this->jobName, $this->systemPrompt, $this->userPrompt, $this->outputFormat, $this->combine ? 'true' : 'false', $this->taskId]);
            return Files::withId($this->jobId);
        }
    }

    public function cancelCelaryTask() {
        $taskId = $this->getTaskId();
        $jobName = $this->getJobName();
        error_log("job name: ".$jobName);
        $success = false;
        $config = require CONFIG_FILE;
        $backend_server = $config["backend_server"];
        $api_key = $config['apiKey'];
        
        $client = new Client();
        $url = $backend_server . '/cancel-celery-task';
        $data = ['task-id' => $taskId, 'job-name' => $jobName];
    
        try {
            error_log("Cancelling task ID: " . $taskId);
    
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'apiKey' => $api_key
                ],
                'json' => $data
            ]);
    
            $responseBody = json_decode($response->getBody()->getContents(), true);
            error_log("AWOOGA: " . print_r($responseBody, true));
    
            if ($responseBody && isset($responseBody["success"]) && $responseBody["success"]) {
                $success = true;
            }
    
            return json_encode(["success" => $success]);
    
        } catch (Exception $e) {
            error_log('Request failed: ' . $e->getMessage());
            return json_encode(["error" => "Exception occurred", "success" => false]);
        }
    }
    
    public static function withTaskId(string $taskId): ?Jobs {
        if (empty($taskId)) {
            throw new PDOException('You must supply a taskId to load');
        }
        $stmt = PostgresDB::run(
            "SELECT * FROM jobs WHERE task_id = :taskId",
            ['taskId' => $taskId]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row !== null && $row !== false) {
            $instance = new self();
            $instance->fill($row);
            return $instance;
        }
        return null;
    }

    public function getJobId() {
        return $this->jobId;
    }

    public function setJobId($jobId) {
        $this->jobId = $jobId;
    }

    public function getFileId() {
        return $this->fileId;
    }

    public function setFileId($fileId) {
        $this->fileId = $fileId;
    }

    public function getJobName() {
        return $this->jobName;
    }

    public function setJobName($jobName) {
        $this->jobName = $jobName;
    }

    public function getSystemPrompt() {
        return $this->systemPrompt;
    }

    public function setSystemPrompt($systemPrompt) {
        $this->systemPrompt = $systemPrompt;
    }

    public function getUserPrompt() {
        return $this->userPrompt;
    }

    public function setUserPrompt($userPrompt) {
        $this->userPrompt = $userPrompt;
    }

    public function getOutputFormat() {
        return $this->outputFormat;
    }

    public function setOutputFormat($outputFormat) {
        $this->outputFormat = $outputFormat;
    }

    public function getCombine() {
        return (bool) $this->combine;
    }

    public function setCombine($combine) {
        $this->combine = (bool) $combine;
    }

    public function getCreatedAt() {
        return $this->createdAt;
    }

    public function setCreatedAt($createdAt) {
        $this->createdAt = $createdAt;
    }

    public function getTaskId() {
        return $this->taskId;
    }

    public function setTaskId($taskId) {
        $this->taskId = $taskId;
    }

    public function jsonSerialize(): array {
        return [
            'jobId' => $this->jobId,
            'fileId' => $this->fileId,
            'jobName' => $this->jobName,
            'systemPrompt' => $this->systemPrompt,
            'userPrompt' => $this->userPrompt,
            'outputFormat' => $this->outputFormat,
            'combine' => (bool) $this->combine,
            'createdAt' => $this->createdAt,
            'taskId' => $this->taskId
        ];
    }
}

?>
