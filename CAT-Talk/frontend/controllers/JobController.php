<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

require_once __DIR__ . '/../utilities/db.php';
include_once MODELS_DIR . 'User.php';
include_once MODELS_DIR . 'Files.php';
include_once UTILITIES_DIR . 'S3ClientWrapper.php';

class JobController {
    public static function startJob(User $user) {
        header("Content-Type: application/json");
        ignore_user_abort(true); // continue execution if a user leaves the page
        set_time_limit(60); // time limit on execution
        $fileIds = [];
        $filepath = "";
        $job = "";
        $success = false;
        $error_message = "";
        $parameters = [];
        $responses = [];

        if (isset($_POST['file_ids']) && is_array($_POST['file_ids'])) {
            $fileIds = $_POST['file_ids'];
        } else {
            $error_message = "Please include an array of file IDs";
            $ret = array('success' => $success, 'error_message' => $error_message);
            echo json_encode($ret);
            return;
        }

        if (isset($_POST['filepath'])) {
            $filepath = $_POST['filepath'];
        } else {
            $error_message = "Please include a filepath";
            $ret = array('success' => $success, 'error_message' => $error_message);
            echo json_encode($ret);
            return;
        }

        if (isset($_POST['synchronifier_transcript_file'])) {
            $synchronifier_transcript_file = $_POST['synchronifier_transcript_file'];
        }

        if (isset($_POST['job'])) {
            $job = $_POST['job'];
        } else {
            $error_message = "Please include a job";
            $ret = array('success' => $success, 'error_message' => $error_message);
            echo json_encode($ret);
            return;
        }

        if (isset($_POST['parameters'])) {
            $parameters = $_POST['parameters'];
        }

        foreach ($fileIds as $fileId) {
            $file = Files::withId($fileId);
            $response = null;
            error_log($job);

            if ($job == "synchronifier") {
                $response = $file->startClearMLTask($job, $parameters, $synchronifier_transcript_file);
            } else {
                $response = $file->startClearMLTask($job, $parameters);
            }

            if ($response === null) {
                Files::updateStatus($fileId, "Failed to start", $job);
                error_log("Could not start a job for file ID: $fileId.");
                $responses[] = array('file_id' => $fileId, 'success' => false, 'error_message' => "Could not start a job.");
                continue;
            }

            $responseData = json_decode($response, true);
            $status = "";
            $message = "";
            if (json_last_error() === JSON_ERROR_NONE) {
                error_log("json decoded");
                $status = isset($responseData['status']) ? $responseData['status'] : 'No status field found';
                $message = isset($responseData['message']) ? $responseData['message'] : 'No message field found';
            } else {
                Files::updateStatus($fileId, "Failed to start", $job);
                error_log("Error decoding JSON response for file ID $fileId: " . json_last_error_msg());
                $responses[] = array('file_id' => $fileId, 'success' => false, 'error_message' => json_last_error_msg());
                continue;
            }

            if ($status === "error") {
                Files::updateStatus($fileId, "Failed to start", $job);
                error_log("status was error for file ID: $fileId");
                $responses[] = array('file_id' => $fileId, 'success' => false, 'error_message' => $message);
                continue;
            } else {
                $taskId = $responseData["task_id"]["task_id"];
                $jobs = new Jobs();
                $jobs = $jobs->create($fileId, $job, "", "", $taskId, "", null);
                Files::updateStatus($fileId, "Started", $job);
                // Create a new metrics entry, passing the job type
                $metrics = new Metrics();
                error_log("Creating metrics for job: $job with task ID: $taskId");
                error_log("Metrics: ".print_r($metrics, true));
                $metrics->setUserId($user->getId());
                if (isset($parameters['user_id'])) {
                    unset($parameters['user_id']);
                }
                $metrics->createWithType($jobs->getTaskId(), $job, $parameters);
                error_log("Metrics: ".print_r($metrics, true));
                $responses[] = array('file_id' => $fileId, 'success' => true, 'error_message' => "");
            }
        }

        echo json_encode($responses);
        return;
    }

    public static function startAllJobs(User $user) {
        header("Content-Type: application/json");
        ignore_user_abort(true); // continue execution if a user leaves the page
        set_time_limit(60); // time limit on execution
        $fileIds = [];
        $filepath = "";
        $job = "";
        $success = false;
        $error_message = "";
        $parameters = [];
        $responses = [];
        if (isset($_POST['file_ids'])) {
            $fileIds = $_POST['file_ids'];
        }
        else {
            $error_message = "Please include a file ID";
            $ret = array('success' => $success, 'error_message' => $error_message);
            echo json_encode($ret);
            return;
        }
        if (isset($_POST['project-id'])) {
            $projectId = $_POST['project-id'];
        }
        else {
            $error_message = "Please include a project-id";
            $ret = array('success' => $success, 'error_message' => $error_message);
            echo json_encode($ret);
            return;
        }
        if (isset($_POST['filepath'])) {
            $filepath = $_POST['filepath'];
        }
        else {
            $error_message = "Please include a file ID";
            $ret = array('success' => $success, 'error_message' => $error_message);
            echo json_encode($ret);
            return;
        }
        if (isset($_POST['synchronifier_transcript_file'])) {
            $synchronifier_transcript_file = $_POST['synchronifier_transcript_file'];
        }
        if (isset($_POST['job'])) {
            $job = $_POST['job'];
        }
        else {
            $error_message = "Please include a job";
            $ret = array('success' => $success, 'error_message' => $error_message);
            echo json_encode($ret);
            return;
        }
        if (isset($_POST['parameters'])) {
            $parameters = $_POST['parameters'];
        }

        error_log("PARAMS: ".print_r($parameters, true));

        $response = null;
        error_log($job);

        $project = Project::withId($projectId);
        $userId = $user->getId();

        $defaultVals = Jobs::getDefaultVals("riskalyzer");
        $defaultUserPrompt = $defaultVals['user_prompt'];
        $defaultSystemPrompt = $defaultVals['system_prompt'];
        $defaultOutputFormat = $defaultVals['output_format'];

        $parameters["user_prompt"] = $defaultUserPrompt;
        $parameters["system_prompt"] = $defaultSystemPrompt;
        $parameters["output_format"] = $defaultOutputFormat;

        error_log($defaultUserPrompt);
        error_log($defaultSystemPrompt);
        error_log($defaultOutputFormat);
        
        if($project->isMember($userId)) {
            foreach ($fileIds as $fileId) {
                $file = Files::withId($fileId);
                if ($job == "synchronifier") {
                    $response = $file->startAllClearMLTasks($job, $parameters, $synchronifier_transcript_file);
                }
                else {
                    $response = $file->startAllClearMLTasks($job, $parameters);
                }
                
                if ($response === null) {
                    Files::updateStatus($fileId, "Failed to start", $job);
                    $responses[] = array('success' => $success, 'error_message' => "Could not start a job.");
                    continue;
                }
        
                $responseData = $response;
                if ($responseData === null || !$responseData["success"]) {
                    error_log("Could not start job.");
        
                    $responses[] = array('success' => $success, 'error_message' => "Could not start job");
                    continue;
                } else {
                    $taskId = $responseData["task-id"];
                    $jobs = new Jobs();
                    $jobs = $jobs->create($fileId, "verbatimizer", "", "", $taskId, "", False);
                    $jobs = new Jobs();
                    $jobs = $jobs->create($fileId, "describalizer", "", "", $taskId, "", False);
                    $jobs = new Jobs();
                    $jobs = $jobs->create($fileId, "riskalyzer", $defaultSystemPrompt, $defaultUserPrompt, $taskId, $defaultOutputFormat, False);
        
                    $success = true;
                    Files::updateStatus($fileId, "Started", "verbatimizer");
                    Files::updateStatus($fileId, "Started", "describalizer");
                    Files::updateStatus($fileId, "Started", "riskalyzer");
                    $responses[] = array('success' => $success, 'error_message' => $error_message);
                    continue;
                }
            }
            echo json_encode($responses);
            return;
        } else {
            $ret = array('success' => $success, 'error_message' => 'User not a member of project');
            echo json_encode($ret);
            return;
        }
    }

    public static function cancelCeleryTask(User $user) {
        header("Content-Type: application/json");
        $success = true; // Assume success initially
        $error_messages = []; // Array to store error messages
        $responses = [];
        $jobName = "";
        $projectId = "";
    
        if (isset($_POST['file_ids']) && is_array($_POST['file_ids'])) {
            $fileIds = $_POST['file_ids'];
        } else {
            echo json_encode([
                'success' => false,
                'error_message' => 'Please include an array of file IDs'
            ]);
            return;
        }
    
        if (isset($_POST['job_name'])) {
            $jobName = $_POST['job_name'];
        } else {
            echo json_encode([
                'success' => false,
                'error_message' => 'Please include a job_name'
            ]);
            return;
        }
    
        if (isset($_POST['project_id'])) {
            $projectId = $_POST['project_id'];
        } else {
            echo json_encode([
                'success' => false,
                'error_message' => 'Please include a project_id'
            ]);
            return;
        }
    
        $project = Project::withId($projectId);
        $userId = $user->getId();
    
        if ($project->isMember($userId)) {
            foreach ($fileIds as $fileId) {
                $job = Jobs::withNameAndFile($jobName, $fileId);
    
                if ($job === null) {
                    $responses[] = array('file_id' => $fileId, 'success' => false, 'error_message' => "Job not found for file ID: $fileId");
                    continue;
                }
    
                Files::updateStatus($fileId, "Canceled", $jobName); // Update status regardless of success
                $response = $job->cancelCelaryTask();
    
                if ($response === null) {
                    $responses[] = array('file_id' => $fileId, 'success' => false, 'error_message' => "Could not cancel job for file ID: $fileId");
                    continue;
                }
    
                $responseData = json_decode($response, true);
                if ($responseData === null || !$responseData["success"]) {
                    $responses[] = array('file_id' => $fileId, 'success' => false, 'error_message' => "Could not cancel job for file ID: $fileId");
                } else {
                    $responses[] = array('file_id' => $fileId, 'success' => true, 'error_message' => "");
                }
            }
    
            echo json_encode($responses);
            return;
        } else {
            echo json_encode([
                'success' => false,
                'error_message' => "User not a member of specified project"
            ]);
            return;
        }
    }

    public static function startCustomJob(User $user) {
        header("Content-Type: application/json");
        ignore_user_abort(true); // continue execution if a user leaves the page
        set_time_limit(60); // time limit on execution
        $fileIds = [];
        $filepath = "";
        $systemPrompt = "";
        $userPrompt = "";
        $outputFormat = "";
        $success = false;
        $error_message = "";
        $parameters = [];
        $responses = [];

        if (isset($_POST['file_ids']) && is_array($_POST['file_ids'])) {
            $fileIds = $_POST['file_ids'];
        } else {
            $error_message = "Please include an array of file IDs";
            $ret = array('success' => $success, 'error_message' => $error_message);
            echo json_encode($ret);
            return;
        }

        if (isset($_POST['job_name']) && strpos($_POST['job_name'], ' ') == false) {
            $jobName = $_POST['job_name'];
        } else {
            $error_message = "Please include a job_name (no spaces)";
            $ret = array('success' => $success, 'error_message' => $error_message);
            echo json_encode($ret);
            return;
        }

        if (isset($_POST['system_prompt'])) {
            $systemPrompt = $_POST['system_prompt'];
        } else {
            $error_message = "Please include a system_prompt";
            $ret = array('success' => $success, 'error_message' => $error_message);
            echo json_encode($ret);
            return;
        }

        if (isset($_POST['user_prompt'])) {
            $userPrompt = $_POST['user_prompt'];
        } else {
            $error_message = "Please include a user_prompt";
            $ret = array('success' => $success, 'error_message' => $error_message);
            echo json_encode($ret);
            return;
        }

        if (isset($_POST['output_format'])) {
            $outputFormat = $_POST['output_format'];
        } else {
            $error_message = "Please include an output_format";
            $ret = array('success' => $success, 'error_message' => $error_message);
            echo json_encode($ret);
            return;
        }

        if (isset($_POST['parameters'])) {
            $parameters = $_POST['parameters'];
            $combine = $parameters["combine"];
        } else {
            $error_message = "Please include parameters";
            $ret = array('success' => $success, 'error_message' => $error_message);
            echo json_encode($ret);
            return;
        }

        foreach ($fileIds as $fileId) {
            $file = Files::withId($fileId);
            $response = null;

            if ($jobName == "riskalyzer") {
                $defaultVals = Jobs::getDefaultVals($jobName);
                $defaultUserPrompt = $defaultVals['user_prompt'];
                $defaultSystemPrompt = $defaultVals['system_prompt'];
                $defaultOutputFormat = $defaultVals['output_format'];
                
                if (is_null($userPrompt) || strlen($userPrompt) == 0) {
                    $userPrompt = $defaultUserPrompt;
                } 
                if (is_null($systemPrompt) || strlen($systemPrompt) == 0) {
                    $systemPrompt = $defaultSystemPrompt;
                }
                if (is_null($outputFormat) || strlen($outputFormat) == 0) {
                    $outputFormat = $defaultOutputFormat;
                    if (is_null($outputFormat)) {
                        $outputFormat = "";
                    }
                }

                Jobs::setDefaultVals($jobName, $systemPrompt, $userPrompt, $outputFormat, False);
            }
            error_log("");
            error_log("");
            error_log("JOB INFO:");
            error_log("fileId: ".$fileId);
            error_log("jobName: ".$jobName);
            error_log("systemPrompt: ".$systemPrompt);
            error_log("userPrompt: ".$userPrompt);
            error_log("outputFormat: ".$outputFormat);
            error_log("combine: ".$combine);
            error_log("");

            $response = $file->startCustomClearMLTask($jobName, $systemPrompt, $userPrompt, $outputFormat, $parameters);
            error_log(print_r($response, true));
            
            if ($response === null) {
                Files::updateStatus($fileId, "Failed to start", $jobName);
                error_log("Could not start a job for file ID: $fileId.");
                $responses[] = array('file_id' => $fileId, 'success' => false, 'error_message' => "Could not start a job.");
                continue;
            }

            $responseData = json_decode($response, true);
            $status = "";
            $message = "";
            if (json_last_error() === JSON_ERROR_NONE) {
                error_log("json decoded");
                $status = isset($responseData['status']) ? $responseData['status'] : 'No status field found';
                $message = isset($responseData['message']) ? $responseData['message'] : 'No message field found';
            } else {
                Files::updateStatus($fileId, "Failed to start", $jobName);
                error_log("Error decoding JSON response for file ID $fileId: " . json_last_error_msg());
                $responses[] = array('file_id' => $fileId, 'success' => false, 'error_message' => json_last_error_msg());
                continue;
            }

            if ($status === "error") {
                Files::updateStatus($fileId, "Failed to start", $jobName);
                error_log("status was error for file ID: $fileId");
                $responses[] = array('file_id' => $fileId, 'success' => false, 'error_message' => $message);
                continue;
            } else {
                $taskId = $responseData["task_id"]["task_id"];
                $jobs = new Jobs();
                $jobs = $jobs->create($fileId, $jobName, $systemPrompt, $userPrompt, $taskId, $outputFormat, $combine);
                Files::updateStatus($fileId, "Started", $jobName);
                // Create a new metrics entry, passing the job type
                $metrics = new Metrics();
                error_log("Creating metrics for job: llm with task ID: $taskId");
                error_log("Metrics: ".print_r($metrics, true));
                $metrics->setUserId($user->getId());
                if (isset($parameters['user_id'])) {
                    unset($parameters['user_id']);
                }
                $metrics->createWithType($jobs->getTaskId(), "llm", $parameters);
                error_log("Metrics: ".print_r($metrics, true));
                $responses[] = array('file_id' => $fileId, 'success' => true, 'error_message' => "");
            }
        }

        echo json_encode($responses);
        return;
    }

    public static function cancelJob(User $user) {
        header("Content-Type: application/json");
        $success = true; // Assume success initially
        $error_messages = []; // Array to store error messages
        $responses = [];
        $jobName = "";
        $projectId = "";
        $collectionCode = "";
    
        if (isset($_POST['file_ids']) && is_array($_POST['file_ids'])) {
            $fileIds = $_POST['file_ids'];
        } else {
            echo json_encode([
                'success' => false,
                'error_message' => 'Please include an array of file IDs'
            ]);
            return;
        }
    
        if (isset($_POST['job_name']) && strpos($_POST['job_name'], ' ') == false) {
            $jobName = $_POST['job_name'];
        } else {
            echo json_encode([
                'success' => false,
                'error_message' => 'Please include a job_name (no spaces)'
            ]);
            return;
        }
    
        if (isset($_POST['project_id']) && strpos($_POST['project_id'], ' ') == false) {
            $projectId = $_POST['project_id'];
        } else {
            echo json_encode([
                'success' => false,
                'error_message' => 'Please include a project_id (no spaces)'
            ]);
            return;
        }
    
        if (isset($_POST['collection_code']) && strpos($_POST['collection_code'], ' ') == false) {
            $collectionCode = $_POST['collection_code'];
        } else {
            echo json_encode([
                'success' => false,
                'error_message' => 'Please include a collection_code (no spaces)'
            ]);
            return;
        }
    
        $project = Project::withId($projectId);
        $userId = $user->getId();
    
        if ($project->isMember($userId)) {
            foreach ($fileIds as $fileId) {
                $job = Jobs::withProjectCollectionFileAndName($projectId, $collectionCode, $fileId, $jobName);
    
                if ($job === null) {
                    $responses[] = array('file_id' => $fileId, 'success' => false, 'error_message' => "Job not found for file ID: $fileId");
                    continue;
                }
    
                $taskId = $job->getTaskId();
                if ($taskId === null) {
                    $responses[] = array('file_id' => $fileId, 'success' => false, 'error_message' => "No task_id associated with job for file ID: $fileId. This might be a legacy job.");
                    continue;
                }
    
                $file = Files::withId($fileId);
                if ($file === null) {
                    $responses[] = array('file_id' => $fileId, 'success' => false, 'error_message' => "File not found for file ID: $fileId");
                    continue;
                }
    
                $response = $file->cancelClearMLTask($taskId);
                Files::updateStatus($fileId, "Canceled", $jobName); // Update status regardless of success
                $responseData = json_decode($response, true);
    
                if ($responseData === null || !$responseData["success"]) {
                    $responses[] = array('file_id' => $fileId, 'success' => false, 'error_message' => "Could not cancel job for file ID: $fileId");
                } else {
                    $responses[] = array('file_id' => $fileId, 'success' => true, 'error_message' => "");
                }
            }
    
            echo json_encode($responses);
            return;
        } else {
            echo json_encode([
                'success' => false,
                'error_message' => "User not a member of specified project"
            ]);
            return;
        }
    }

    private static function startJobPost(string $fileId, string $job) {
        $success = false;
        $config = include 'config.php';
        $backend_server = $config['backend_server'];
        $api_key = $config['apiKey'];

        // Create a new Guzzle client
        $client = new Client();

        $url = $backend_server . '/api/job/' . $job;

        // Prepare the data
        $file = Files::withId($fileId);
        $data = [
            'filepath' => $file->getFilename(),
            'project_id' => "oral_history", // CHANGE THIS EVENTUALLY
            'collection_code' => $file->getCollectionCode(),
            'file_id' => $fileId
        ];

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

    public static function getJobFields(User $user) {
        header("Content-Type: application/json");
        $success = false;
        $error_message = [];

        if (!isset($_GET['project_id'])) {
            $error_message['error'][] = 'You must provide a project_id';
        } else {
            $projectId = $_GET['project_id'];
        }
        if (!isset($_GET['collection_code'])) {
            $error_message['error'][] = 'You must provide a collection_code';
        } else {
            $collectionCode = $_GET['collection_code'];
        }
        if (!isset($_GET['job_name'])) {
            $error_message['error'][] = 'You must provide a job_name';
        } else {
            $jobName = $_GET['job_name'];
        }
        if (!isset($_GET['file_id'])) {
            $error_message['error'][] = 'You must provide a file_id';
        } else {
            $fileId = $_GET['file_id'];
        }

        if (!empty($error_message['error'])) {
            echo json_encode($error_message);
            return;
        }
         
        $job = Jobs::withProjectCollectionFileAndName($projectId, $collectionCode, $fileId, $jobName);

        if (!is_null($job)) {
            echo json_encode($job->jsonSerialize());
        } else {
            echo json_encode(['error' => 'Job not found']);
        }
        
    }
}
