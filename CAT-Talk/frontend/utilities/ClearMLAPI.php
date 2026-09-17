<?php

/**
 * ClearMLAPI - A PHP wrapper for the ClearML API
 * 
 * This wrapper provides an easy-to-use interface for interacting with the ClearML API
 * from PHP applications. It covers core functionality including authentication, project
 * management, task management, model management, and dataset operations.
 */
class ClearMLAPI {
    private $apiServer;
    private $accessKey;
    private $secretKey;
    private $token;
    private $httpClient;

    /**
     * Constructor for ClearMLAPI wrapper
     * 
     * @param string $apiServer The ClearML API server URL (e.g., "https://api.clear.ml")
     * @param string $accessKey Your ClearML access key
     * @param string $secretKey Your ClearML secret key
     */
    public function __construct($apiServer, $accessKey = null, $secretKey = null) {
        $this->apiServer = rtrim($apiServer, '/');
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->token = null;
        
        // Initialize HTTP client (using cURL)
        $this->initHttpClient();
        
        // Authenticate if credentials are provided
        if ($accessKey && $secretKey) {
            $this->authenticate();
        }
    }
    
    /**
     * Initialize HTTP client with default options
     */
    private function initHttpClient() {
        $this->httpClient = curl_init();
        curl_setopt($this->httpClient, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->httpClient, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->httpClient, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
    }
    
    /**
     * Authenticate with ClearML API using provided credentials
     * 
     * @return bool True if authentication was successful
     * @throws Exception If authentication fails
     */
    public function authenticate() {
        $data = [
            'access_key' => $this->accessKey,
            'secret_key' => $this->secretKey
        ];
        
        $response = $this->sendRequest('POST', '/auth.login', $data);
        
        if (isset($response['data']['token'])) {
            $this->token = $response['data']['token'];
            return true;
        } else {
            throw new Exception("Authentication failed: " . json_encode($response));
        }
    }
    
    /**
     * Send an HTTP request to the ClearML API
     * 
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @return array Response data
     * @throws Exception If the request fails
     */
    private function sendRequest($method, $endpoint, $data = null) {
        $url = $this->apiServer . $endpoint;
        
        curl_setopt($this->httpClient, CURLOPT_URL, $url);
        curl_setopt($this->httpClient, CURLOPT_CUSTOMREQUEST, $method);
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        if ($this->token) {
            $headers[] = "Authorization: Bearer {$this->token}";
        }
        
        curl_setopt($this->httpClient, CURLOPT_HTTPHEADER, $headers);
        
        if ($data !== null) {
            curl_setopt($this->httpClient, CURLOPT_POSTFIELDS, json_encode($data));
        } else {
            curl_setopt($this->httpClient, CURLOPT_POSTFIELDS, null);
        }
        
        $response = curl_exec($this->httpClient);
        $httpCode = curl_getinfo($this->httpClient, CURLINFO_HTTP_CODE);
        
        if ($response === false) {
            throw new Exception("cURL error: " . curl_error($this->httpClient));
        }
        
        $decoded = json_decode($response, true);
        
        if ($httpCode >= 400) {
            throw new Exception("API error (Code: $httpCode): " . json_encode($decoded));
        }
        
        return $decoded;
    }
    
    /**
     * Close HTTP client connection
     */
    public function __destruct() {
        if ($this->httpClient) {
            curl_close($this->httpClient);
        }
    }
    
    /**
     * Get user information
     * 
     * @return array User information
     */
    public function getCurrentUser() {
        return $this->sendRequest('GET', '/users.get_current_user');
    }
    
    /*
     * PROJECT MANAGEMENT
     */
    
    /**
     * Create a new project
     * 
     * @param string $name Project name
     * @param string $description Project description (optional)
     * @return array Created project details
     */
    public function createProject($name, $description = '') {
        $data = [
            'name' => $name,
            'description' => $description
        ];
        
        return $this->sendRequest('POST', '/projects.create', $data);
    }
    
    /**
     * Get project by ID
     * 
     * @param string $projectId Project ID
     * @return array Project details
     */
    public function getProject($projectId) {
        return $this->sendRequest('GET', "/projects.get_by_id?project_id=$projectId");
    }
    
    /**
     * Get list of projects
     * 
     * @param array $filters Filter criteria (optional)
     * @param int $offset Pagination offset (optional)
     * @param int $limit Maximum number of results (optional)
     * @return array List of projects
     */
    public function getProjects($filters = [], $offset = 0, $limit = 100) {
        $data = [
            'filters' => $filters,
            'offset' => $offset,
            'limit' => $limit
        ];
        
        return $this->sendRequest('POST', '/projects.get_all', $data);
    }
    
    /**
     * Update project details
     * 
     * @param string $projectId Project ID
     * @param array $updates Fields to update
     * @return array Updated project details
     */
    public function updateProject($projectId, $updates) {
        $data = array_merge(['project' => $projectId], $updates);
        return $this->sendRequest('POST', '/projects.update', $data);
    }
    
    /**
     * Delete a project
     * 
     * @param string $projectId Project ID
     * @return array Response details
     */
    public function deleteProject($projectId) {
        $data = ['project' => $projectId];
        return $this->sendRequest('POST', '/projects.delete', $data);
    }
    
    /*
     * TASK MANAGEMENT
     */
    
    /**
     * Create a new task
     * 
     * @param string $name Task name
     * @param string $projectId Project ID (optional)
     * @param string $type Task type (optional, e.g., 'training', 'testing')
     * @param array $tags Task tags (optional)
     * @return array Created task details
     */
    public function createTask($name, $projectId = null, $type = 'training', $tags = []) {
        $data = [
            'name' => $name,
            'type' => $type,
            'tags' => $tags
        ];
        
        if ($projectId) {
            $data['project'] = $projectId;
        }
        
        return $this->sendRequest('POST', '/tasks.create', $data);
    }
    
    /**
     * Get task by ID
     * 
     * @param string $taskId Task ID
     * @return array Task details
     */
    public function getTask($taskId) {
        return $this->sendRequest('GET', "/tasks.get_by_id?task_id=$taskId");
    }
    
    /**
     * Get list of tasks
     * 
     * @param array $filters Filter criteria (optional)
     * @param int $offset Pagination offset (optional)
     * @param int $limit Maximum number of results (optional)
     * @return array List of tasks
     */
    public function getTasks($filters = [], $offset = 0, $limit = 100) {
        $data = [
            'filters' => $filters,
            'offset' => $offset,
            'limit' => $limit
        ];
        
        return $this->sendRequest('POST', '/tasks.get_all', $data);
    }
    
    /**
     * Update task details
     * 
     * @param string $taskId Task ID
     * @param array $updates Fields to update
     * @return array Updated task details
     */
    public function updateTask($taskId, $updates) {
        $data = array_merge(['task' => $taskId], $updates);
        return $this->sendRequest('POST', '/tasks.update', $data);
    }
    
    /**
     * Delete a task
     * 
     * @param string $taskId Task ID
     * @return array Response details
     */
    public function deleteTask($taskId) {
        $data = ['task' => $taskId];
        return $this->sendRequest('POST', '/tasks.delete', $data);
    }
    
    /**
     * Add task metrics
     * 
     * @param string $taskId Task ID
     * @param string $title Metric title
     * @param float $value Metric value
     * @param int $iteration Iteration number
     * @return array Response details
     */
    public function addTaskMetric($taskId, $title, $value, $iteration = 0) {
        $data = [
            'task' => $taskId,
            'title' => $title,
            'series' => 'series',
            'value' => $value,
            'iteration' => $iteration
        ];
        
        return $this->sendRequest('POST', '/events.add_scalar', $data);
    }
    
    /**
     * Get task metrics
     * 
     * @param string $taskId Task ID
     * @return array Task metrics
     */
    public function getTaskMetrics($taskId) {
        return $this->sendRequest('GET', "/events.get_task_metrics?task_id=$taskId");
    }
    
    /**
     * Log task console output
     * 
     * @param string $taskId Task ID
     * @param string $msg Console message
     * @param string $level Log level (optional)
     * @return array Response details
     */
    public function logToTask($taskId, $msg, $level = 'info') {
        $data = [
            'task' => $taskId,
            'msg' => $msg,
            'level' => $level
        ];
        
        return $this->sendRequest('POST', '/events.add_log', $data);
    }
    
    /**
     * Get task logs
     * 
     * @param string $taskId Task ID
     * @param int $batch_size Number of log entries to return (default: 500)
     * @return array Task logs
     */
    public function getTaskLogs($taskId, $batch_size = 500) {
        return $this->sendRequest('GET', "/events.get_task_log?task_id=$taskId&batch_size=$batch_size");
    }
    
    /*
     * MODEL MANAGEMENT
     */
    
    /**
     * Create a new model
     * 
     * @param string $name Model name
     * @param string $projectId Project ID
     * @param string $uri Model URI (where model is stored)
     * @param array $tags Model tags (optional)
     * @return array Created model details
     */
    public function createModel($name, $projectId, $uri, $tags = []) {
        $data = [
            'name' => $name,
            'project' => $projectId,
            'uri' => $uri,
            'tags' => $tags
        ];
        
        return $this->sendRequest('POST', '/models.create', $data);
    }
    
    /**
     * Get model by ID
     * 
     * @param string $modelId Model ID
     * @return array Model details
     */
    public function getModel($modelId) {
        return $this->sendRequest('GET', "/models.get_by_id?model_id=$modelId");
    }
    
    /**
     * Get list of models
     * 
     * @param array $filters Filter criteria (optional)
     * @param int $offset Pagination offset (optional)
     * @param int $limit Maximum number of results (optional)
     * @return array List of models
     */
    public function getModels($filters = [], $offset = 0, $limit = 100) {
        $data = [
            'filters' => $filters,
            'offset' => $offset,
            'limit' => $limit
        ];
        
        return $this->sendRequest('POST', '/models.get_all', $data);
    }
    
    /**
     * Update model details
     * 
     * @param string $modelId Model ID
     * @param array $updates Fields to update
     * @return array Updated model details
     */
    public function updateModel($modelId, $updates) {
        $data = array_merge(['model' => $modelId], $updates);
        return $this->sendRequest('POST', '/models.update', $data);
    }
    
    /**
     * Delete a model
     * 
     * @param string $modelId Model ID
     * @return array Response details
     */
    public function deleteModel($modelId) {
        $data = ['model' => $modelId];
        return $this->sendRequest('POST', '/models.delete', $data);
    }
    
    /*
     * DATASET MANAGEMENT
     */
    
    /**
     * Create a new dataset
     * 
     * @param string $name Dataset name
     * @param string $projectId Project ID
     * @param array $tags Dataset tags (optional)
     * @return array Created dataset details
     */
    public function createDataset($name, $projectId, $tags = []) {
        $data = [
            'name' => $name,
            'project' => $projectId,
            'tags' => $tags
        ];
        
        return $this->sendRequest('POST', '/datasets.create', $data);
    }
    
    /**
     * Get dataset by ID
     * 
     * @param string $datasetId Dataset ID
     * @return array Dataset details
     */
    public function getDataset($datasetId) {
        return $this->sendRequest('GET', "/datasets.get_by_id?dataset_id=$datasetId");
    }
    
    /**
     * Get list of datasets
     * 
     * @param array $filters Filter criteria (optional)
     * @param int $offset Pagination offset (optional)
     * @param int $limit Maximum number of results (optional)
     * @return array List of datasets
     */
    public function getDatasets($filters = [], $offset = 0, $limit = 100) {
        $data = [
            'filters' => $filters,
            'offset' => $offset,
            'limit' => $limit
        ];
        
        return $this->sendRequest('POST', '/datasets.get_all', $data);
    }
    
    /**
     * Update dataset details
     * 
     * @param string $datasetId Dataset ID
     * @param array $updates Fields to update
     * @return array Updated dataset details
     */
    public function updateDataset($datasetId, $updates) {
        $data = array_merge(['dataset' => $datasetId], $updates);
        return $this->sendRequest('POST', '/datasets.update', $data);
    }
    
    /**
     * Delete a dataset
     * 
     * @param string $datasetId Dataset ID
     * @return array Response details
     */
    public function deleteDataset($datasetId) {
        $data = ['dataset' => $datasetId];
        return $this->sendRequest('POST', '/datasets.delete', $data);
    }
    
    /**
     * Add files to dataset
     *
     * @param string $datasetId Dataset ID
     * @param array $fileEntries Array of file entries (each with path and file_name)
     * @return array Response details
     */
    public function addFilesToDataset($datasetId, $fileEntries) {
        $data = [
            'dataset' => $datasetId,
            'files' => $fileEntries
        ];
        
        return $this->sendRequest('POST', '/datasets.add_files', $data);
    }
    
    /**
     * Remove files from dataset
     *
     * @param string $datasetId Dataset ID
     * @param array $fileNames Array of file names to remove
     * @return array Response details
     */
    public function removeFilesFromDataset($datasetId, $fileNames) {
        $data = [
            'dataset' => $datasetId,
            'files' => $fileNames
        ];
        
        return $this->sendRequest('POST', '/datasets.remove_files', $data);
    }
}