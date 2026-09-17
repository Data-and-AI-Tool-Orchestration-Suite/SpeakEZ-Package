<?php

const METRICS_VIEWS_DIR = __DIR__ . "/../views/metrics/";
require_once __DIR__ . '/../utilities/db.php';
require_once MODELS_DIR . 'Metrics.php';
require_once __DIR__ . '/../models/Files.php';


class MetricsController {

    // Return all file durations for a tenant (GET param: tenant_id, start_date, end_date)
    public static function listDurationForTenant(User $user, $tenantId) {
        header("Content-Type: application/json");
        if (!$tenantId) {
            echo json_encode(["success" => false, "error" => "Missing tenant_id"]);
            return;
        }
        global $rootURL;
        $userId = $user->getId();
        $tenant = Tenant::withId($tenantId);
        if (!$tenant){
            $_SESSION['FLASH_ERROR'] = "Invalid Tenant ID.";
            header("Location: $rootURL/");
            die();
        } 
        if (!$tenant->hasRole($user->getId(), "Admin")
             && !$user->isAdmin()
            ){
            $_SESSION['FLASH_ERROR'] = "You are not an Administrator of this tenant.";
            header("Location: $rootURL/");
            die();
        }
        $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : null;
        $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : null;
        try {
            $rows = Files::getDurationsByTenantAndDateRange($tenantId, $startDate, $endDate);
            echo json_encode(["success" => true, "data" => $rows]);
        } catch (Exception $e) {
            echo json_encode(["success" => false, "error" => $e->getMessage()]);
        }
    }

    public static function getTotalNumTranscriptsByTenant(User $user, $tenantId) {
        header("Content-Type: application/json");
        if (!$tenantId) {
            echo json_encode(["success" => false, "error" => "Missing tenant_id"]);
            return;
        }
        global $rootURL;
        $userId = $user->getId();
        $tenant = Tenant::withId($tenantId);
        if (!$tenant){
            $_SESSION['FLASH_ERROR'] = "Invalid Tenant ID.";
            header("Location: $rootURL/");
            die();
        } 
        if (!$tenant->hasRole($user->getId(), "Admin")
             && !$user->isAdmin()
            ){
            $_SESSION['FLASH_ERROR'] = "You are not an Administrator of this tenant.";
            header("Location: $rootURL/");
            die();
        }
        try {
            $total = Files::getTotalNumTranscriptsByTenant($tenantId);
            echo json_encode(["success" => true, "total_num_transcripts" => $total]);
        } catch (Exception $e) {
            echo json_encode(["success" => false, "error" => $e->getMessage()]);
        }
    }  

    
    public static function index(User $user): void {
        global $rootURL;
        require METRICS_VIEWS_DIR . 'index.php';
    }

    public static function update() {
        header("Content-Type: application/json");
        global $rootURL;
        $config = require CONFIG_FILE;
        $success = false;
        $error_message = "";
        $headers = getallheaders();
        $receivedApiKey = isset($headers['Authorization']) ? trim(str_replace('Bearer', '', $headers['Authorization'])) : '';
        if ($receivedApiKey !== $config['api_key']) {
            $error_message = "Invalid API Key";
        } else {
            $jsonData = file_get_contents('php://input');
            $data = json_decode($jsonData, true);

            try {
                if (!isset($data['task_id'])){
                    $error_message = "You must provide a task ID.";
                } else {
                    try {
                        $taskId = $data['task_id'];
                        $job = Jobs::withTaskId($taskId);
                        if (is_null($job)){
                            $error_message = "Job not found";
                        } else {
                            $metrics = Metrics::withTaskId($taskId);
                            if (!is_null($metrics)) {
                                // construct details based on job type. post request will contain job type and parameters
                                if (isset($data['job_type'])) {
                                    $jobType = $data['job_type'];
                                    $parameters = $data['parameters'] ?? [];
                                    if ($jobType === 'transcribe' || $jobType === 'synchronify' || $jobType === 'llm') {
                                        $costs = Metrics::calculateOpenAICosts(array_merge(['type' => $jobType], $parameters));
                                        $parameters = array_merge($parameters, $costs);
                                    }
                                    $metrics->updateDetailsWithType($jobType, $parameters);
                                } else {
                                    $error_message = "You must provide a job_type.";
                                }
                            } else {
                                $error_message = "Metrics not found for task ID: " . $taskId;
                            }
                            $success = $error_message === "";
                        }
                    } catch (Exception $e) {
                        $error_message = $e->getMessage();
                    }
                }
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }

        $ret = array('success' => $success, 'error_message' => $error_message);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    // Retrieve file duration info by file_id
    public static function getFileDuration(User $user) {
        header("Content-Type: application/json");
        try {
            $rows = Files::getAllDurations();
            echo json_encode(["success" => true, "data" => $rows]);
        } catch (Exception $e) {
            echo json_encode(["success" => false, "error" => $e->getMessage()]);
        }
    }

    // Return the total file duration for a tenant (GET param: tenant_id)
    public static function getTotalFileDurationForTenant(User $user, $tenantId) {
        header("Content-Type: application/json");
        if (!$tenantId) {
            echo json_encode(["success" => false, "error" => "Missing tenant_id"]);
            return;
        }
        global $rootURL;
        $userId = $user->getId();
        $tenant = Tenant::withId($tenantId);
        if (!$tenant){
            $_SESSION['FLASH_ERROR'] = "Invalid Tenant ID.";
            header("Location: $rootURL/");
            die();
        } 
        if (!$tenant->hasRole($user->getId(), "Admin")
             && !$user->isAdmin()
            ){
            $_SESSION['FLASH_ERROR'] = "You are not an Administrator of this tenant.";
            header("Location: $rootURL/");
            die();
        }
        $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : null;
        $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : null;
        try {
            $total = Files::getTotalDurationByTenantAndDateRange($tenantId, $startDate, $endDate);
            echo json_encode(["success" => true, "total_duration_seconds" => $total]);
        } catch (Exception $e) {
            echo json_encode(["success" => false, "error" => $e->getMessage()]);
        }
    }

    
    public static function listUsageForDatatable(User $user) {
        header("Content-Type: application/json");
        
        $start = 0;
        if (isset($_GET['start']))
            $start = intval($_GET['start']);
        $length = 10;
        if (isset($_GET['length']))
            $length = intval($_GET['length']);
        $filter = '';
        if (isset($_GET['search']['value']))
            $filter = $_GET['search']['value'];
        $order_by = '';
        if (isset($_GET['order'][0]['column']))
            $order_by = $_GET['order'][0]['column'];
        $order_dir = 'desc';
        if (isset($_GET['order'][0]['dir']))
            $order_dir = $_GET['order'][0]['dir'];
        $data = array();
        $idx = $start;

        $requestType = '';
        if (isset($_GET['request_type']))
            $requestType = $_GET['request_type'];
        
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
        
        $results = Metrics::listUsageForDatatable($requestType, $start, $length, $order_by, $order_dir, $filter, $start_date, $end_date);
        foreach ($results as $result) {
            $data_row = $result;
            $data_row['DT_RowId'] = $idx++;
            array_push($data, $data_row);
        }
        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => intval(Metrics::countUsageForDatatable($requestType)),
                'recordsFiltered' => intval(Metrics::countUsageFilteredForDatatable($requestType, $filter)),
                'data' => $data,
            )
        );
    }

    public static function listUsageBreakdown(User $user) {
        header("Content-Type: application/json");
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
        $results = Metrics::listUsageBreakdownByUser($start_date, $end_date);
        echo json_encode(["data" => $results]);
    }

    public static function listUsageBreakdownForTenant(User $user, $tenantId) {
        header("Content-Type: application/json");
        global $rootURL;
        $userId = $user->getId();
        $tenant = Tenant::withId($tenantId);
        if (!$tenant){
            $_SESSION['FLASH_ERROR'] = "Invalid Tenant ID.";
            header("Location: $rootURL/");
            die();
        } 
        if (!$tenant->hasRole($user->getId(), "Admin")
             && !$user->isAdmin()
            ){
            $_SESSION['FLASH_ERROR'] = "You are not an Administrator of this tenant.";
            header("Location: $rootURL/");
            die();
        }
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
        $results = Metrics::listUsageBreakdownByTenant($tenantId, $start_date, $end_date);
        echo json_encode(["data" => $results]);
    }

    public static function getRecentMetrics(User $user) {
        header("Content-Type: application/json");
        if (!$user->isAdmin()) {
            http_response_code(403); // Forbidden
            echo json_encode([
                "error" => "Only site admins can view metrics"
            ]);
            die();
        }
        if (!isset($_GET['starting_date'])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "No starting date provided"
            ]);
            die();
        }
        $starting_date = $_GET['starting_date'];
        $direction = $_GET['direction'] ?? 'after';

        if ($direction != "after" && $direction != "before") {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "direction parameter must be 'before' or 'after'"
            ]);
            die();
        }
        try {
            $metrics = Metrics::loadByAddedDate($starting_date, $direction);
            http_response_code(200); // OK
            echo json_encode([
                "metrics" => $metrics
            ]);
            die();
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                "error" => $e->getMessage()
            ]);
            die();
        }
    }
}
