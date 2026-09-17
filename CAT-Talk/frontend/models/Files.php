<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

require_once __DIR__ . '/../utilities/db.php';
require_once __DIR__ . '/../utilities/UUID.php';
// Ensure S3Utility is available
require_once __DIR__ . '/../utilities/S3Utility.php'; 

class Files implements JsonSerializable {    

    /**
     * Helper to construct the S3 Object Key.
     */
    private static function getS3Key(string $folder, string $projectId, string $collectionCode, string $filename): string {
        return trim($folder, '/') . '/' . $projectId . '/' . $collectionCode . '/' . trim($filename, '/');
    }

    /**
     * Get all file_duration rows for a tenant, optionally filtered by date range.
     * @param string $tenantId
     * @param string|null $startDate
     * @param string|null $endDate
     * @return array
     */
    public static function getDurationsByTenantAndDateRange($tenantId, $startDate = null, $endDate = null) {
        $query = "
            SELECT f.filename, cc.code AS collection_code, p.name AS project_name, fd.duration_seconds, fd.created_at, fd.updated_at
            FROM file_duration fd
            JOIN files f ON fd.file_id = f.id
            JOIN collection_codes cc ON f.collection_code_id = cc.id
            JOIN projects p ON f.associated_project_id = p.id
            WHERE p.tenant_id = :tenant_id
        ";
        $params = ["tenant_id" => $tenantId];
        if ($startDate) {
            $query .= " AND fd.created_at >= :start_date";
            $params["start_date"] = $startDate;
        }
        if ($endDate) {
            $query .= " AND fd.created_at <= :end_date";
            $params["end_date"] = $endDate;
        }
        $stmt = PostgresDB::run($query, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected $id;
    protected $collectionCode;
    protected $filename;
    protected $transcriptionFilename;
    protected $izers;
    protected $dateUploaded;
    protected $archived;
    protected $status;
    protected $associated_project_id;
    protected $fileType;


    public function __construct() { }

    public function create($filename, $coll_code, $projectId, $transcriptionFilename=null, $fileType=null) {
        error_log("Creating file with filename: {$filename}, collection code: {$coll_code}, projectId: {$projectId}, transcriptionFilename: {$transcriptionFilename}, fileType: {$fileType}");
        
        $exists = self::withFilenameCollectionAndProject($filename, $coll_code, $projectId);
        if ($exists) {
            error_log("exists");
            $now = new DateTime('now', new DateTimeZone('UTC'));
            $exists->dateUploaded = $now->format('Y-m-d H:i:s');
            error_log("status: " . print_r($exists->status, true));
            
            // Update the status array to set "status" to "Re-uploaded"
            if (isset($exists->status['status']) && is_array($exists->status['status'])) {
                $exists->status['status']['status'] = "Re-uploaded";
            } else {
                $exists->status['status'] = ["status" => "Re-uploaded"];
            }
            
            $exists->archived = array("archived" => false, "days_left" => 90);
            // Set fileType if provided
            if (!is_null($fileType)) {
                $exists->fileType = $fileType;
            }
            // Only set transcriptionFilename if provided and not empty
            if (!is_null($transcriptionFilename) && $transcriptionFilename !== '') {
                $exists->transcriptionFilename = $transcriptionFilename;
            }
            // Always set associated_project_id to the provided projectId
            $exists->associated_project_id = $projectId;
            $file = $exists->save();
            return $file;
        } else {
            error_log("does not exist");
            $this->id = UUID::v4();
            $this->collectionCode = $coll_code;
            $this->filename = $filename;
            if (!is_null($transcriptionFilename) && $transcriptionFilename !== ''){
                $this->transcriptionFilename = $transcriptionFilename;
            }
            $this->izers = array();
            $now = new DateTime('now', new DateTimeZone('UTC'));
            $this->dateUploaded = $now->format('Y-m-d H:i:s');
            $this->archived = array("archived" => false, "days_left" => 90);
            $this->status = array("status" => array("status"=>"Uploaded"));
            $this->associated_project_id = $projectId;
            $this->fileType = $fileType;
            $this->save();
            return $this;
        }
    }


    public static function archiveFromId(string $uuid): bool {
        $exists = Files::withId($uuid);
        if (is_null($exists))
            throw new Exception('File not found');

        return $exists->archive();
    }

    public static function deleteFilePathFromId(string $uuid): bool {
        $exists = Files::withId($uuid);
        if (is_null($exists))
            throw new Exception('File not found');

        return $exists->delete();
    }

    public static function getFilesPathsFromIds(array $ids): array {
        $stmt = PostgresDB::run("SELECT filename FROM files WHERE id IN (SELECT jsonb_array_elements_text(:ids::jsonb))", ["ids" => json_encode($ids)]);
        $filepaths = [];
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            $filepaths[] = $row['filename'];
        return $filepaths;
    }

    protected function archive(): bool {
        $stmt = PostgresDB::prepare("UPDATE files SET archived = '{\"archived\": true, \"days_left\": 0}' WHERE id = :uuid");
        $stmt->bindParam('uuid', $this->id);
        $stmt->execute();
        if ($stmt->rowCount() > 0)
            return true;
        else
            return false;
    }

    public static function deleteById($id): bool {
        $query = "DELETE FROM files WHERE id = :id";
        $stmt = PostgresDB::prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return is_null(self::withId($id));
    }

    public static function withId(string $id): ?Files {
        try {
            $instance = new self();
            $instance->loadById($id);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function withFilename(string $filename): ?Files {
        try {
            $instance = new self();
            $instance->loadByFilename($filename);
            return $instance;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return null;
        }
    }

    public static function withFilenameAndCollection(string $filename, string $collectionCode): ?Files {
        try {
            $instance = new self();
            $instance->loadByFilenameAndCollection($filename, $collectionCode);
            return $instance;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return null;
        }
    }

    public static function withFilenameCollectionAndProject(string $filename, string $collectionCode, string $projectId): ?Files {
        try {
            $instance = new self();
            $instance->loadByFilenameCollectionAndProject($filename, $collectionCode, $projectId);
            return $instance;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return null;
        }
    }

    public static function countForDatatable($collCodes): int {
        $query = "SELECT count(id) FROM files";
        if ($collCodes !== "") {
            $query .= " WHERE collection_code_id = :collCodes";
            $stmt = PostgresDB::prepare($query);
            $stmt->bindParam(':collCodes', $collCodes);
        } else {
            $stmt = PostgresDB::prepare($query);
        }

        $stmt->execute();
        return $stmt->fetchColumn();
    }


    public static function countFilteredForDatatable(string $filter, string $collCodes): int {
        $query = "SELECT count(id) FROM files";
        if (!is_null($filter) && strlen($filter) > 0) {
            $filter = "%{$filter}%";
            if ($collCodes !== "") {
                $query .= " WHERE ((filename::text LIKE :filter) OR transcription_filename::text LIKE :filter) AND collection_code_id = :collCodes";
            } else {
                $query .= " WHERE ((filename::text LIKE :filter) OR transcription_filename::text LIKE :filter)";
            }

        } else if ($collCodes !== "") {
            $query .= " WHERE collection_code_id = :collCodes";
        }
        $stmt = PostgresDB::prepare($query);
        if (!is_null($filter) && strlen($filter) > 0) {
            $stmt->bindParam('filter', $filter);
            if ($collCodes !== "") {
                $stmt->bindParam('collCodes', $collCodes);
            }
        } else if ($collCodes !== "") {
            $stmt->bindParam('collCodes', $collCodes);
        }
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    /**
     * Replaces shell `mc cat`.
     */
    public static function getFileContent(string $projectId, string $collectionCode, string $fileName) {
        global $CONFIG;
        
        $bucketName = $CONFIG["s3"]["top_level_bucket"];
        $outputFolder = $CONFIG["s3"]["output_bucket"];
        
        // Path: output/projectId/collectionCode/fileName
        $targetKey = self::getS3Key($outputFolder, $projectId, $collectionCode, $fileName);
        
        $s3 = S3Utility::createConnection();
        return $s3->catFile($bucketName, $targetKey);
    }

    /**
     * Replaces shell `mc pipe`.
     */
    public static function saveFileContent(string $projectId, string $collectionCode, string $fileName, string $content) {
        global $CONFIG;
        
        $bucketName = $CONFIG["s3"]["top_level_bucket"];
        $outputFolder = $CONFIG["s3"]["output_bucket"];
        
        // Path: output/projectId/collectionCode/fileName
        $targetKey = self::getS3Key($outputFolder, $projectId, $collectionCode, $fileName);
        
        $s3 = S3Utility::createConnection();
        
        // Upload the content
        $s3_success = $s3->echoIntoFile($bucketName, $targetKey, $content);
        
        if (!$s3_success) {
            error_log("S3 Upload failed for $targetKey");
            return false;
        }

        // Notify Backend
        $client = new Client();
        $config = require CONFIG_FILE;
        $api_key = $config['apiKey'];
        $backend_server = $config["backend_server"];
        $url = $backend_server . '/create-formatted-transcripts';
        
        error_log('\n\n\n'.print_r($url, true));
        
        $data = [
            'file_name' => $fileName,
            'collection_code' => $collectionCode,
            'project_id' => $projectId
        ];
        
        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'apiKey' => $api_key
                ],
                'json' => $data,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode === 200) {
                return true;
            }
        } catch (Exception $e) {
            error_log('Request failed: ' . $e->getMessage());
        }

        return false;
    }


    public static function listForDatatable(int $start, int $length, string $order_by, string $order_dir, string $filter, string $collCodes, string $project_id): array {
        $matches = [];
        $query = "SELECT * FROM public.files f
                  JOIN public.collection_codes cc ON f.collection_code_id = cc.id";
        $conditions = ["cc.project_id = :project_id"];
    
        // Apply filter logic
        if (!empty($filter)) {
            $conditions[] = "(f.filename ILIKE :filter OR f.transcription_filename ILIKE :filter)";
        }
        
        if (!empty($collCodes)) {
            $conditions[] = "f.collection_code_id = :collCodes";
        }
    
        // Construct WHERE clause dynamically
        if (!empty($conditions)) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }
    
        // Set default sorting order
        $order_by = in_array($order_by, ['filename', 'transcription_filename', 'date_added']) ? $order_by : 'date_added';
        $order_dir = strtoupper($order_dir) === 'ASC' ? 'ASC' : 'DESC';
    
        $query .= " ORDER BY $order_by $order_dir";
    
        // Add pagination
        if ($length > 0) {
            $query .= " OFFSET :start ROWS FETCH NEXT :length ROWS ONLY";
        }
    
        // Prepare and bind parameters
        $stmt = PostgresDB::prepare($query);
        $stmt->bindParam(':project_id', $project_id, PDO::PARAM_STR);
    
        if (!empty($filter)) {
            $stmt->bindValue(':filter', '%' . $filter . '%', PDO::PARAM_STR);
        }
        
        if (!empty($collCodes)) {
            $stmt->bindParam(':collCodes', $collCodes, PDO::PARAM_STR);
        }
        
        if ($length > 0) {
            $stmt->bindParam(':start', $start, PDO::PARAM_INT);
            $stmt->bindParam(':length', $length, PDO::PARAM_INT);
        }
    
        // Execute and fetch results
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_LAZY)) {
            $matches[] = Files::withRow($row);
        }
    
        return $matches;
    }
        
    public static function withRow( $row ): Files {
        $instance = new self();
        $instance->fill( $row );
        return $instance;
    }

    public static function getCollectionCodes(): array {
        $stmt = PostgresDB::run("SELECT collection_code_id FROM files");
        $codes = array();
        while ($row = $stmt->fetch(PDO::FETCH_LAZY))
            if (!in_array($row['collection_code_id'], $codes)) {
                $codes[] = $row['collection_code_id']; // Using $codes[] is equivalent to array_push($codes, ...)
            }
        return $codes;
    }

    public static function updateStatus($uuid, $status, $action): void {
        $exists = Files::withId($uuid);
        if (is_null($exists))
            throw new Exception('File not found');

        // if the only thing in the status array is "Uploaded" or "Re-uploaded" then just overwrite it with new stuff.
        if (array_key_exists('status', $exists->status['status']) && ($exists->status['status'] === "Uploaded" || $exists->status['status'] === "Re-uploaded")) {
            $exists->status['status'] = array($action => $status);
        } else {
            $exists->status['status'][$action] = $status;
        }

        if (strtolower($status) === "complete"){
            if (!in_array($action, $exists->izers)) {
                $exists->izers[] = $action;
            } //else don't add
        }
        $exists->save();

    }

    public static function getJobs(string $fileId, string $projectId) {
        try {
            $stmt = PostgresDB::run(
                "
                SELECT DISTINCT job_name
                FROM (
                    SELECT DISTINCT jsonb_array_elements_text(jobs::jsonb) AS job_name
                    FROM public.files
                    WHERE id = :file_id
                        AND associated_project_id = :project_id
                ) subquery
                WHERE job_name NOT IN ('verbatimizer', 'izers');
                ",
                ['file_id' => $fileId, 'project_id' => $projectId]
            );
            
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log('Database error in getJobs: ' . $e->getMessage());
            return [];
        }
    }
    

    public function createClearMLDataset(){
        $success = false;
        $config = require CONFIG_FILE;
        $backend_server = $config["backend_server"];
        $api_key = $config['apiKey'];

        $client = new Client();
        $url = $backend_server . '/create-clearml-dataset';
        
        // S3 Info
        $topLevelBucket = $config["s3"]["top_level_bucket"];
        $audioFolder = $config["s3"]["bucket"]; // e.g. "audiofiles"

        $data = [
            'file_id' => $this->getId(),
            'collection_code' => $this->getCollectionCodeName(),
            'dataset_name' => $this->getAssociatedProjectId() . "_" . $this->getId(),
            // Logic: TopLevel/AudioFolder/ProjectID
            'bucket_name' => $topLevelBucket . "/" . $audioFolder . "/" . $this->getAssociatedProjectId(),
            'file_name' => $this->getFilename()
        ];

        error_log(print_r($data, true));

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'apiKey' => $api_key
                ],
                'json' => $data, 
            ]);

            $responseBody = $response->getBody()->getContents();
            error_log(print_r($responseBody, true));
            $success = true;

        } catch (Exception $e) {
            error_log('Request failed: ' . $e->getMessage());
            $success = false;
        }
        return $success;
    }

    public function createClearMLDatasetSynchronifier(){
        $success = false;
        $config = require CONFIG_FILE;
        $backend_server = $config["backend_server"];
        $api_key = $config['apiKey'];

        $client = new Client();
        $url = $backend_server . '/create-clearml-dataset';

        // S3 Info
        $topLevelBucket = $config["s3"]["top_level_bucket"];
        $audioFolder = $config["s3"]["bucket"]; // e.g. "audiofiles"

        $data = [
            'synchronifier' => true,
            'file_id' => $this->getTranscriptionFilename(),
            'collection_code' => $this->getCollectionCodeName(),
            'dataset_name' => $this->getAssociatedProjectId() . "_" . $this->getTranscriptionFilename(),
             // Logic: TopLevel/AudioFolder/ProjectID
            'bucket_name' => $topLevelBucket . "/" . $audioFolder . "/" . $this->getAssociatedProjectId(),
            'file_name' => $this->getTranscriptionFilename()
        ];

        error_log("Sending following data to /create-clearml-dataset: ");
        error_log(print_r($data, true));

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'apiKey' => $api_key
                ],
                'json' => $data, 
            ]);

            $responseBody = $response->getBody()->getContents();
            error_log("Response from /create-clearml-dataset: ");
            error_log(print_r($responseBody, true));
            $success = true;

        } catch (Exception $e) {
            error_log('Request failed: ' . $e->getMessage());
            $success = false;
        }
        return $success;
    }

    public function createClearMLDatasetTranscript(){
        $success = false;
        $config = require CONFIG_FILE;
        $backend_server = $config["backend_server"];
        $api_key = $config['apiKey'];

        $client = new Client();
        $url = $backend_server . '/create-clearml-dataset';

        // S3 Info
        $topLevelBucket = $config["s3"]["top_level_bucket"];
        $outputFolder = $config["s3"]["output_bucket"]; // e.g. "output"

        $data = [
            'file_id' => $this->getId(),
            'collection_code' => $this->getCollectionCodeName(),
            'dataset_name' => $this->getAssociatedProjectId() . "_" . $this->getId() . "_outputs",
            // Logic: TopLevel/OutputFolder/ProjectID
            'bucket_name' => $topLevelBucket . "/" . $outputFolder . "/" . $this->getAssociatedProjectId(),
            'file_name' => pathinfo($this->getFilename(), PATHINFO_FILENAME) . '.transcript'
        ];

        error_log("[createClearMLDatasetTranscript] Data: " . print_r($data, true));

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'apiKey' => $api_key
                ],
                'json' => $data, 
            ]);

            $responseBody = $response->getBody()->getContents();
            error_log("[createClearMLDatasetTranscript] Response: " . print_r($responseBody, true));
            $success = true;

        } catch (Exception $e) {
            error_log('[createClearMLDatasetTranscript] Request failed: ' . $e->getMessage());
            $success = false;
        }
        return $success;
    }

    // Edit this to allow more ClearML tasks if more are defined
    public function startClearMLTask(string $taskName, $parameters = [], $synchronifier_transcript_file = null){
        $success = false;
        $config = require CONFIG_FILE;
        $backend_server = $config["backend_server"];
        $api_key = $config['apiKey'];


        // Create a new Guzzle client
        $client = new Client();

        $url = $backend_server . '/start-task';

        // Prepare the data
        $data = [
            'task_name' => $taskName,
            'project_id' => $this->getAssociatedProjectId(),
            'collection_id' => $this->getCollectionCodeName(),
            'file_id' => $this->getId(),
            'file_name' => $this->getFilename(),
        ];
        $parameters['og_filename'] = $this->getFilename();
        $data['parameters'] = $parameters;


        error_log("Starting clearml task with parameters: ");
        error_log("data: ".print_r($data,true));

        if ($synchronifier_transcript_file !== null) {
            $data['synchronifier_transcript_file'] = $synchronifier_transcript_file;
        }

        try {
            // Send the POST request
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'apiKey' => $api_key
                ],
                'json' => $data // Automatically converts the array to JSON and sets the Content-Type header
            ]);

            // Get the response body
            $responseBody = $response->getBody()->getContents();

            // Log the response
            error_log(print_r($responseBody, true));

            $success = true;

        } catch (Exception $e) {
            error_log('Request failed: ' . $e->getMessage());
        }
        return $success ? $responseBody : null;
    }

    public function cancelClearMLTask(string $taskId) {
        $success = false;
        $config = require CONFIG_FILE;
        $backend_server = $config["backend_server"];
        $api_key = $config['apiKey'];


        // Create a new Guzzle client
        $client = new Client();

        $url = $backend_server . '/cancel-task';

        // Prepare the data
        $data = [
            'task_id' => $taskId
        ];

        error_log("Cancelling clearml task with parameters: ");
        error_log("data: ".print_r($data,true));

        try {
            // Send the POST request
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'apiKey' => $api_key
                ],
                'json' => $data // Automatically converts the array to JSON and sets the Content-Type header
            ]);

            // Get the response body
            $responseBody = $response->getBody()->getContents();

            // Log the response
            error_log(print_r("response: ".$responseBody, true));

            $success = true;
            $thing = $responseBody;
            error_log("returning: ".print_r($thing, true));
            return $responseBody;

        } catch (Exception $e) {
            error_log('Request failed: ' . $e->getMessage());
            return json_encode(array("error" => "Could not cancel job", "success" => False));
        }
    }

    public function startAllClearMLTasks(string $taskName, $parameters = [], $synchronifier_transcript_file = null){
        $success = false;
        $config = require CONFIG_FILE;
        $backend_server = $config["backend_server"];
        $api_key = $config['apiKey'];


        // Create a new Guzzle client
        $client = new Client();

        $url = $backend_server . '/start-all-tasks';

        // Prepare the data
        $data = [
            'task_name' => $taskName,
            'project_id' => $this->getAssociatedProjectId(),
            'collection_id' => $this->getCollectionCodeName(),
            'file_id' => $this->getId(),
            'file_name' => $this->getFilename(),
        ];
        if (!empty($parameters)) {
            $data['parameters'] = $parameters;
        }

        error_log("Starting clearml task with parameters: ");
        error_log("data: ".print_r($data,true));

        if ($synchronifier_transcript_file !== null) {
            $data['synchronifier_transcript_file'] = $synchronifier_transcript_file;
        }

        try {
            // Send the POST request
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'apiKey' => $api_key
                ],
                'json' => $data // Automatically converts the array to JSON and sets the Content-Type header
            ]);

            // Get the response body
            $responseBody = $response->getBody()->getContents();

            // Log the response
            error_log("START ALL TASKS RESPONSE: ".print_r($responseBody, true));

            $success = true;
            return json_decode($responseBody, true);

        } catch (Exception $e) {
            error_log('Request failed: ' . $e->getMessage());
        }
    }

    public function startCustomClearMLTask(string $jobName, string $system_prompt, string $user_prompt, string $outputFormat, $parameters = []){
        $success = false;
        $config = require CONFIG_FILE;
        $backend_server = $config["backend_server"];
        $api_key = $config['apiKey'];
        $taskName = "custom";

        // Create a new Guzzle client
        $client = new Client();

        $url = $backend_server . '/start-task';

        // Prepare the data
        $data = [
            'task_name' => $taskName.'-'.$jobName,
            'project_id' => $this->getAssociatedProjectId(),
            'collection_id' => $this->getCollectionCodeName(),
            'file_id' => $this->getId(),
            'file_name' => $this->getFilename(),
            'parameters' => [
                'system_prompt' => $system_prompt,
                'user_prompt' => $user_prompt,
                'schema' => $outputFormat,
            ]
        ];
        foreach ($parameters as $key => $value) { // add additional parameters
            $data['parameters'][$key] = $value;
        }
        
        error_log(print_r($data,true));

        try {
            // Send the POST request
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'apiKey' => $api_key
                ],
                'json' => $data // Automatically converts the array to JSON and sets the Content-Type header
            ]);

            // Get the response body
            $responseBody = $response->getBody()->getContents();

            // Log the response
            error_log(print_r($responseBody, true));

            $success = true;

        } catch (Exception $e) {
            error_log('Request failed: ' . $e->getMessage());
        }
        return $success ? $responseBody : null;
    }


    public static function getCollectionCodeById(string $id): ?string {
        try {
            $stmt = PostgresDB::run(
                "SELECT collection_code_id FROM files WHERE id = :id",
                ['id' => $id]
            );
            $row = $stmt->fetch(PDO::FETCH_LAZY);
            if ($row) {
                return $row['collection_code_id'];
            } else {
                return null; // Handle case where no record is found
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return null; // Handle any database errors
        }
    }

    public static function getCollectionCodeNameById(string $id): ?string {
        try {
            $stmt = PostgresDB::run(
                "SELECT collection_codes.code 
                 FROM files 
                 JOIN collection_codes ON files.collection_code_id = collection_codes.id
                 WHERE files.id = :id",
                ['id' => $id]
            );
            $row = $stmt->fetch(PDO::FETCH_LAZY);
            if ($row) {
                return $row['code']; // Return the code from collection_codes table
            } else {
                return null; // Handle case where no record is found
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return null; // Handle any database errors
        }
    }
    
    public function isReupload(): bool {
        try {
            $status = $this->getStatus()['status']['status'];
            if ($status === "Re-uploaded") {
                return true;
            }
            return false;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return null; // Handle any database errors
        }
    }
    

    protected function loadById(string $id): void {
        if (empty($id))
            throw new PDOException('You must supply an id to load');

        $stmt = PostgresDB::run(
            "SELECT * FROM files WHERE id = :id",
            ['id' => $id]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row <> null)
            $this->fill( $row );
        else
            throw new PDOException("File with id [{$id}] not found");
    }

    protected function loadByFilename(string $filename): void {
        if (!$filename || empty($filename))
            throw new PDOException('You must supply a filename to load');

        $stmt = PostgresDB::run(
            "SELECT *
                  FROM public.files
                  WHERE filename = :filename 
                  OR transcription_filename = :filename;
            ",
            ["filename" => $filename]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row <> null)
            $this->fill( $row );
        else
            throw new PDOException("File with filename [{$filename}] not found");
    }

    protected function loadByFilenameAndCollection(string $filename, string $collectionCode): void {
        if (!$filename || empty($filename))
            throw new PDOException('You must supply a filename to load');

        $stmt = PostgresDB::run(
            "SELECT *
                  FROM public.files
                  WHERE (filename = :filename OR transcription_filename = :filename)
                  AND collection_code = :collection_code;
            ",
            ["filename" => $filename, "collection_code" => $collectionCode]
        );
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row <> null)
            $this->fill( $row );
        else
            throw new PDOException("File with filename [{$filename}] not found");
    }

    protected function loadByFilenameCollectionAndProject(string $filename, string $collectionCode, string $projectId): void {
        if (!$filename || empty($filename)) {
            throw new PDOException('You must supply a filename to load');
        }
    
        $stmt = PostgresDB::run(
            "SELECT f.*
             FROM public.files f
             JOIN public.collection_codes cc ON f.collection_code_id = cc.id
             WHERE (f.filename = :filename OR f.transcription_filename = :filename)
               AND cc.id = :collection_code
               AND cc.project_id = :project_id;",
            [
                "filename" => $filename,
                "collection_code" => $collectionCode,
                "project_id" => $projectId
            ]
        );
    
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row !== false) {
            $this->fill($row);
            error_log("this: " . print_r($this, true));
        } else {
            throw new PDOException("File with filename [{$filename}] not found in collection [{$collectionCode}] for project [{$projectId}]");
        }
    }
    
    protected function fill( $row ): void {
        $this->id = $row['id'];
        $this->collectionCode = $row['collection_code_id'];
        $this->filename = $row['filename'];
        $this->transcriptionFilename = $row['transcription_filename'];
        $this->izers = json_decode($row['izers'], true);
        $this->dateUploaded = $row['date_added'];
        $this->archived = json_decode($row['archived'], true);
        $this->status = json_decode($row['status'], true);
        $this->associated_project_id = $row['associated_project_id'];
        $this->fileType = $row['file_type'] ?? null;
    }

    protected function save(): Files {
        $exists = Files::withFilenameCollectionAndProject($this->filename, $this->collectionCode, $this->associated_project_id);
        if (is_null($exists)){
            PostgresDB::run("INSERT INTO files (id, collection_code_id, filename, transcription_filename, izers, date_added, archived, status, associated_project_id, file_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?);",
                [
                    $this->id,
                    $this->collectionCode,
                    $this->filename,
                    $this->transcriptionFilename,
                    json_encode($this->izers),
                    $this->dateUploaded,
                    json_encode($this->archived),
                    json_encode($this->status),
                    $this->associated_project_id,
                    $this->fileType
                ]);
            return Files::withId($this->id);
        } else {
            PostgresDB::run("UPDATE files SET collection_code_id = ?,  filename = ?, transcription_filename = ?, izers = ?, date_added = ?, archived = ?, status = ?, associated_project_id = ?, file_type = ? WHERE id = ?",
                [
                    $this->collectionCode,
                    $this->filename,
                    $this->transcriptionFilename,
                    json_encode($this->izers),
                    $this->dateUploaded,
                    json_encode($this->archived),
                    json_encode($this->status),
                    $this->associated_project_id,
                    $this->fileType,
                    $exists->id
                ]);
            return Files::withId($exists->id);
        }
    }

    public function getAssociatedProjectId(): string {
        $stmt = PostgresDB::run("
            SELECT p.id AS project_id
            FROM files f
            JOIN collection_codes cc ON f.collection_code_id = cc.id
            JOIN projects p ON cc.project_id = p.id
            WHERE f.id = :file_id;
        ", ["file_id" => $this->id]);
    
        $result = $stmt->fetchColumn();
    
        return $result;
    }

    public function updateTranscriptionFilename(string $transcriptionFilename): void {
        $this->setTranscriptionFilename($transcriptionFilename);
        $this->save();
    }
    
    public function getId(): string {
        return $this->id;
    }

    public function setId(string $id): void {
        $this->id = $id;
    }

    public function getCollectionCodeId(): string {
        return $this->collectionCode;
    }

    public function getCollectionCodeName(): string {
        $collectionId = $this->getCollectionCodeId();
    
        $stmt = PostgresDB::run(
            "SELECT code FROM public.collection_codes WHERE id = :collection_id;",
            ["collection_id" => $collectionId]
        );
    
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if ($row !== false) {
            return $row['code'];
        } else {
            throw new PDOException("Collection with ID [{$collectionId}] not found.");
        }
    }
    

    public function setCollectionCode(string $code): void {
        $this->collectionCode = $code;
    }

    public function getFilename(): string {
        return $this->filename;
    }

    public function setFilename(string $filename): void {
        $this->filename = $filename;
    }

    public function getTranscriptionFilename(): ?string {
        return $this->transcriptionFilename;
    }

    public function setTranscriptionFilename(?string $transcriptionFilename): void {
        $this->transcriptionFilename = $transcriptionFilename;
    }

    public function getIzers(): array {
        return $this->izers;
    }

    public function setIzers(array $izers): void {
        $this->izers = $izers;
    }

    public function getDateUploaded(): string {
        return $this->dateUploaded;
    }

    public function setDateUploaded(string $dateUploaded): void {
        $this->dateUploaded = $dateUploaded;
    }

    public function getArchived(): array {
        return $this->archived;
    }

    public function isArchived(): bool {
        error_log(print_r($this->getArchived(), true));
        error_log(print_r(isset($archived['archived']) && $archived['archived'] === true, true));
        $archived = $this->getArchived();
        return isset($archived['archived']) && $archived['archived'] === true;
    }

    public function setArchived(array $archived): void {
        $this->archived = $archived;
    }

    public function getStatus(): array {
        return $this->status;
    }

    public function setStatus(array $status): void {
        $this->status = $status;
    }

    public function setAssociatedProjectId(string $associated_project_id): void {
        $this->associated_project_id = $associated_project_id;
    }

    public function getFileType(): ?string {
        return $this->fileType;
    }
    public function setFileType(?string $fileType): void {
        $this->fileType = $fileType;
    }

    /**
     * Set the duration (in seconds) for this file in the file_duration table.
     * @param float $duration
     */
    public function setDuration($duration) {
        // Insert or update duration for this file
        $query = "INSERT INTO file_duration (file_id, duration_seconds, created_at, updated_at)
                  VALUES (:file_id, :duration, now(), now())
                  ON CONFLICT (file_id) DO UPDATE SET duration_seconds = EXCLUDED.duration_seconds, updated_at = now();";
        $stmt = PostgresDB::prepare($query);
        $stmt->bindParam(':file_id', $this->id);
        $stmt->bindParam(':duration', $duration);
        $stmt->execute();
    }

    /**
     * Retrieve file duration info by file_id from file_duration table.
     * @param string $fileId
     * @return array|null
     */
    public static function getDurationByFileId($fileId) {
        $stmt = PostgresDB::run("SELECT * FROM file_duration WHERE file_id = :file_id", ["file_id" => $fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }

    /**
     * Get the total duration (in seconds) of all files for a given tenant.
     * @param string $tenantId
     * @return float Total duration in seconds
     */
    public static function getTotalDurationByTenant($tenantId) {
        $query = "
            SELECT COALESCE(SUM(fd.duration_seconds), 0) AS total_duration
            FROM file_duration fd
            JOIN files f ON fd.file_id = f.id
            JOIN projects p ON f.associated_project_id = p.id
            WHERE p.tenant_id = :tenant_id
        ";
        $stmt = PostgresDB::run($query, ["tenant_id" => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? floatval($row['total_duration']) : 0.0;
    }

    /**
     * Retrieve all file durations from file_duration table.
     * @return array
     */
    public static function getAllDurations() {
        $stmt = PostgresDB::run("SELECT * FROM file_duration");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the total duration (in seconds) of all files for a given tenant, optionally filtered by date range.
     * @param string $tenantId
     * @param string|null $startDate (Y-m-d or Y-m-d H:i:s)
     * @param string|null $endDate (Y-m-d or Y-m-d H:i:s)
     * @return float Total duration in seconds
     */
    public static function getTotalDurationByTenantAndDateRange($tenantId, $startDate = null, $endDate = null) {
        $query = "
            SELECT COALESCE(SUM(fd.duration_seconds), 0) AS total_duration
            FROM file_duration fd
            JOIN files f ON fd.file_id = f.id
            JOIN projects p ON f.associated_project_id = p.id
            WHERE p.tenant_id = :tenant_id
        ";
        $params = ["tenant_id" => $tenantId];
        if ($startDate) {
            $query .= " AND fd.created_at >= :start_date";
            $params["start_date"] = $startDate;
        }
        if ($endDate) {
            $query .= " AND fd.created_at <= :end_date";
            $params["end_date"] = $endDate;
        }
        $stmt = PostgresDB::run($query, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? floatval($row['total_duration']) : 0.0;
    }

    public static function getTotalNumTranscriptsByTenant($tenantId, $startDate = null, $endDate = null) {
        $query = "
            SELECT COUNT(*) AS total_transcripts
            FROM files f
            JOIN projects p ON f.associated_project_id = p.id
            WHERE p.tenant_id = :tenant_id
              AND f.file_type = 'transcript'
        ";
        $params = ["tenant_id" => $tenantId];
        if ($startDate) {
            $query .= " AND f.created_at >= :start_date";
            $params["start_date"] = $startDate;
        }
        if ($endDate) {
            $query .= " AND f.created_at <= :end_date";
            $params["end_date"] = $endDate;
        }
        $stmt = PostgresDB::run($query, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? intval($row['total_transcripts']) : 0;
    }

    public function jsonSerialize(): array {
        return [
            'id'                      => $this->getId(),
            'collection_code_id'         => $this->getCollectionCodeId(),
            'collection_code'           => $this->getCollectionCodeName(),
            'filename'                => $this->getFilename(),
            'transcription_filename'  => $this->getTranscriptionFilename(),
            'verbatimizer'            => in_array('verbatimizer' , $this->getIzers()),
            'synchronifier'            => in_array('synchronifier' , $this->getIzers()),
            'describalizer'           => in_array('describalizer' , $this->getIzers()),
            'ohmsifier'               => in_array('ohmsifier' , $this->getIzers()),
            'riskalyzer'              => in_array('riskalyzer' , $this->getIzers()),
            'date_uploaded'           => $this->getDateUploaded(),
            'archived'                => json_encode($this->getArchived()),
            'status'                  => json_encode($this->getStatus()),
            'associated_project_id'   => $this->getAssociatedProjectId(),
            'file_type'               => $this->getFileType(),
        ];
    }
}