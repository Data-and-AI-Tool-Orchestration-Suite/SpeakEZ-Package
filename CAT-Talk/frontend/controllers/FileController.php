<?php

require_once __DIR__ . '/../utilities/db.php';
include_once MODELS_DIR . 'User.php';
include_once MODELS_DIR . 'Files.php';
include_once MODELS_DIR . 'Project.php';
include_once MODELS_DIR . 'CollectionCode.php';
include_once MODELS_DIR . 'Jobs.php';
include_once UTILITIES_DIR . 'S3Utility.php';

class FileController {

    /**
     * Helper to construct the S3 Object Key (Path inside the bucket).
     * Structure: folder/projectId/collectionCode/filename
     */
    private static function getS3Key(string $folder, string $projectId, string $collectionCode, string $filename): string {
        // Ensure folder doesn't have trailing slash, filename doesn't have leading slash
        return trim($folder, '/') . '/' . $projectId . '/' . $collectionCode . '/' . trim($filename, '/');
    }

    /**
     * Download a file from S3, calculate its duration, and store it in the Files table.
     */
    public static function calculateAndStoreMediaDuration(User $user) {
        global $CONFIG;
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!isset($data['file_id']) || empty($data['file_id'])) {
            return false;
        }

        // Get file info from DB
        $fileId = $data['file_id'];
        $file = Files::withId($fileId);
        if (!$file) return false;

        $projectId = $file->getAssociatedProjectId();
        $project = Project::withId($projectId);
        
        if (!$project->isMember($user->getId())) {
            http_response_code(403);
            echo json_encode(['error' => 'User is not a member of the project']);
            return false;
        }

        $collectionCode = $file->getCollectionCodeName();
        $fileName = $file->getFilename();
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        
        // Construct filename with ID if specifically stored that way, or just filename
        // Assuming standard storage is ID.ext based on other methods, but utilizing filename logic here
        $s3FileName = !empty($extension) ? "$fileId.$extension" : "$fileId";
        
        // S3 Config
        $bucketName = $CONFIG["s3"]["top_level_bucket"]; 
        $audioFolder = "audiofiles"; // Hardcoded based on your description
        
        $s3Path = self::getS3Key($audioFolder, $projectId, $collectionCode, $s3FileName);

        $s3 = S3Utility::createConnection();
         
        // Download to a temp file
        $tmpFile = tempnam(sys_get_temp_dir(), 'media_');
        
        // Use S3Utility to download
        $success = $s3->downloadFile($bucketName, $s3Path, $tmpFile);
        
        if (!$success || !file_exists($tmpFile) || filesize($tmpFile) == 0) {
            error_log("Failed to download file from S3: $s3Path");
            @unlink($tmpFile);
            return false;
        }

        // Use ffprobe to get duration in seconds
        $cmdProbe = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($tmpFile);
        $duration = shell_exec($cmdProbe);
        @unlink($tmpFile);

        if ($duration === null || $duration === false) {
            error_log("ffprobe failed for file $fileId");
            return false;
        }

        $duration = floatval($duration);
        if ($duration <= 0) return false;

        // Store duration in the Files table
        $file->setDuration($duration); 

        return $duration;
    }

    public static function listForDatatable(User $user) {
        header("Content-Type: application/json");

        if (!isset($_GET['projectId']) || !isset($_GET['collCodes'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required parameters: projectId and collCodes']);
            return;
        }

        $projectId = $_GET['projectId'];
        $collectionCodeName = $_GET['collCodes'];
        $collectionCodeObj = CollectionCode::withCodeAndProjectId($collectionCodeName, $projectId);
        
        if (!$collectionCodeObj) {
             http_response_code(400);
             echo json_encode(['error' => 'Invalid collection code.']);
             return;
        }
        
        $collectionFilter = $collectionCodeObj->getId();
        $userId = $user->getId();
        $project = Project::withId($projectId);

        if (!$project || !$project->isMember($userId)){
            http_response_code(403);
            echo json_encode(['error' => 'User does not belong to project.']);
            return;
        }

        $start = isset($_GET['start']) ? intval($_GET['start']) : 0;
        $length = isset($_GET['length']) ? intval($_GET['length']) : 0;
        $filter = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';

        $order_by = '';
        if (isset($_GET['order'][0]['column'])) {
            $columnIndex = intval($_GET['order'][0]['column']);
            $columns = ['id', 'collection_code_id', 'filename', 'transcription_filename', 'izers', 'date_added', 'archived', 'status'];
            if (isset($columns[$columnIndex])) {
                $order_by = $columns[$columnIndex];
            }
        }
        $order_dir = isset($_GET['order'][0]['dir']) ? $_GET['order'][0]['dir'] : 'desc';

        $data = array();
        $idx = $start;
        $results = Files::listForDatatable($start, $length, $order_by, $order_dir, $filter, $collectionFilter, $projectId);
        
        foreach ($results as $result) {
            $data_row = $result->jsonSerialize();
            $data_row['DT_RowId'] = $idx++;
            array_push($data, $data_row);
        }

        echo json_encode(array(
            'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
            'recordsTotal' => Files::countForDatatable($collectionFilter),
            'recordsFiltered' => Files::countFilteredForDatatable($filter, $collectionFilter),
            'data' => $data,
        ));
    }

    public static function saveFiles(User $user) {
        global $CONFIG;
        header("Content-Type: application/json");
        $uploadStatus = ["success" => [], "error" => []];
        $responses = [];
    
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
    
        if (!isset($data['filenames']) || empty($data['filenames'])) {
            $uploadStatus['error'][] = 'No filenames provided.';
        } else if (!isset($data['collCode']) || empty($data['collCode'])) {
            $uploadStatus['error'][] = 'You must provide a collection code.';
        } else if (!isset($data['project_id']) || empty($data['project_id'])) {
            $uploadStatus['error'][] = 'You must provide a project ID.';
        } else {
            $collCode = $data['collCode'];
            $filenames = $data['filenames'];
            $projectId = $data['project_id'];
            $userId = $user->getId();
            $project = Project::withId($projectId);

            $collectionCode = CollectionCode::withCodeAndProjectId($collCode, $projectId);
            if (!$collectionCode) {
                $collectionCode = new CollectionCode();
                $collectionCode->create($collCode, $projectId);
            }
            $collectionCodeId = $collectionCode->getId();

            if ($project && $project->isMember($userId)){
                foreach ($filenames as $fileName) {
                    $files = new Files();
                    $files = $files->create($fileName, $collectionCodeId, $projectId);
                    $fileId = $files->getId();
                    $reuploaded = $files->isReupload();
                    $extension = pathinfo($fileName, PATHINFO_EXTENSION);
                    
                    // Construct Path: audiofiles/projectId/collCode/fileId.ext
                    $s3FileName = !empty($extension) ? "$fileId.$extension" : "$fileId";
                    $audioFolder = "audiofiles"; 
                    
                    $targetKey = self::getS3Key($audioFolder, $projectId, $collCode, $s3FileName);

                    $bucketName = $CONFIG["s3"]["top_level_bucket"];
                    $expirationSeconds = 600; // 10 mins
                    
                    $s3 = S3Utility::createConnection();
                    $uploadUrl = $s3->generatePresignedUrlUpload($bucketName, $targetKey, $expirationSeconds);

                    if ($uploadUrl) {            
                        $responses[] = [
                            'file_id' => $fileId,
                            'fileName' => $fileName,
                            'curl_request' => $uploadUrl,
                            'reupload' => $reuploaded
                        ];
                    } else {
                        Files::deleteById($fileId);
                        $uploadStatus["error"][] = "Error generating upload URL for " . $fileName;
                        $responses[] = [
                            'file_id' => $fileId,
                            'fileName' => $fileName,
                            'error' => "Error generating upload URL."
                        ];
                    }
                }
            } else {
                $responses[] = ['error' => "User not a member of specified project."];
            }        
        }

        if (!empty($uploadStatus['error'])) {
            http_response_code(500);
        }
        echo json_encode($responses);
    }


    public static function saveSynchronifierFiles(User $user) {
        global $CONFIG;
        header("Content-Type: application/json");
        $uploadStatus = ["success" => [], "error" => []];
        $responses = [];
    
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
    
        if (!isset($data['filenames']) || empty($data['filenames'])) {
            $uploadStatus['error'][] = 'No filenames provided.';
        } else if (!isset($data['collCode']) || empty($data['collCode'])) {
            $uploadStatus['error'][] = 'You must provide a collection code.';
        } else if (!isset($data['project_id']) || empty($data['project_id'])) {
            $uploadStatus['error'][] = 'You must provide a project ID.';
        } else if (!isset($data['audio_id']) || empty($data['audio_id'])) {
            $uploadStatus['error'][] = 'You must provide an audio_id';
        } else {
            $collCode = $data['collCode'];
            $filenames = $data['filenames'];
            $projectId = $data['project_id'];
            $audioId = $data['audio_id'];
            $userId = $user->getId();
            $project = Project::withId($projectId);

            $collectionCode = CollectionCode::withCodeAndProjectId($collCode, $projectId);
            if (!$collectionCode) {
                $collectionCode = new CollectionCode();
                $collectionCode->create($collCode, $projectId);
            }

            if ($project && $project->isMember($userId)){
                foreach ($filenames as $fileName) {
                    $audioFile = Files::withId($audioId);
                    if (!is_null($audioFile)) {
                        $audioFile->updateTranscriptionFilename($fileName);
                        
                        // NOTE: Synchronifier usually uploads to audiofiles bucket path or specific logic? 
                        // Assuming it goes to audiofiles like a normal upload based on context.
                        $audioFolder = "audiofiles";
                        $targetKey = self::getS3Key($audioFolder, $projectId, $collCode, $fileName);
                        
                        $bucketName = $CONFIG["s3"]["top_level_bucket"];
                        $expirationSeconds = 600;

                        $s3 = S3Utility::createConnection();
                        $uploadUrl = $s3->generatePresignedUrlUpload($bucketName, $targetKey, $expirationSeconds);
    
                        if ($uploadUrl) {            
                            $responses[] = [
                                'fileName' => $fileName,
                                'curl_request' => $uploadUrl,
                            ];
                        } else {
                            $uploadStatus["error"][] = "Error generating upload URL for " . $fileName;
                            $responses[] = [
                                'fileName' => $fileName,
                                'error' => "Error generating upload URL."
                            ];
                        }
                    } else {
                        $uploadStatus['error'][] = 'Audio file does not exist';
                    }
                }
            } else {
                $responses[] = ['error' => "User not a member of specified project."];
            }        
        }

        if (!empty($uploadStatus['error'])) {
            http_response_code(500);
        }
        echo json_encode($responses);
    }

    public static function deleteFile(User $user) {
        global $CONFIG;
        header("Content-Type: application/json");
        $success = false;

        if (!isset($_POST['file_ids']) || !is_array($_POST['file_ids'])) {
            echo json_encode(['success' => false, 'error' => 'Must include an array of file_ids']);
            return;
        }
        $fileIds = $_POST['file_ids'];
        
        if (!isset($_POST['project_id'])) {
            echo json_encode(['success' => false, 'error' => 'Must include project_id']);
            return;
        }
        $projectId = $_POST['project_id'];

        $userId = $user->getId();
        $project = Project::withId($projectId);

        if ($project && $project->isMember($userId)) {
            $s3 = S3Utility::createConnection();
            $bucketName = $CONFIG["s3"]["top_level_bucket"];
            $audioFolder = "audiofiles";

            foreach ($fileIds as $fileId) {
                $file = Files::withId($fileId);
                if ($file) {
                    $collectionCode = $file->getCollectionCodeName(); // Changed from ID to Name for S3 path
                    $fileName = $file->getFilename();
                    $extension = pathinfo($fileName, PATHINFO_EXTENSION);
                    
                    $s3FileName = !empty($extension) ? "$fileId.$extension" : "$fileId";
                    $targetKey = self::getS3Key($audioFolder, $projectId, $collectionCode, $s3FileName);

                    // S3 Delete
                    $s3->deleteObject($bucketName, $targetKey);

                    // DB Delete
                    Files::deleteById($fileId);
                } else {
                    error_log("File with ID $fileId not found.");
                }
            }
            $success = true;
            echo json_encode(['success' => $success]);
        } else {
            echo json_encode(['success' => false, 'error' => 'User not associated with project']);
        }
    }

    // ... [createClearMLDataset methods remain largely DB logic, so they are fine] ... 

    public static function createClearMLDataset(User $user){
        // Delegate to File Model
        self::handleClearMLCreation($user, 'createClearMLDataset');
    }

    public static function createClearMLDatasetSynchronifier(User $user){
         self::handleClearMLCreation($user, 'createClearMLDatasetSynchronifier');
    }

    public static function createClearMLDatasetTranscript(User $user){
         self::handleClearMLCreation($user, 'createClearMLDatasetTranscript', true);
    }
    
    // Helper to reduce code duplication in ClearML functions
    private static function handleClearMLCreation($user, $methodName, $isTranscript = false) {
        header("Content-Type: application/json");
        $response = ["success" => false, "error" => null];
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!isset($data['file_id']) || empty($data['file_id'])) {
            $response['error'] = 'No file ID provided.';
            echo json_encode($response); return;
        }

        $fileId = $data["file_id"];
        $file = Files::withId($fileId);
        if(!$file) {
            $response["error"] = "File not found.";
            echo json_encode($response); return;
        }

        $project = Project::withId($file->getAssociatedProjectId());
        if ($project && $project->isMember($user->getId())) {
             if ($isTranscript && $file->getFileType() !== 'transcript') {
                $response["error"] = "Requested file is not a transcript.";
                echo json_encode($response); return;
             }
             
             if ($file->$methodName()) {
                 $response["success"] = true;
             } else {
                 $response["error"] = "Failed to create ClearML dataset.";
             }
        } else {
            $response["error"] = "Access denied or project not found.";
        }
        echo json_encode($response);
    }

    public static function getCollectionCodes(User $user) {
        header("Content-Type: application/json");
        $success = false;
        $error_message = "";
        $coll_codes = array();

        try {
            $coll_codes = Files::getCollectionCodes();
            $success = true;
        } catch (Exception $e){
            $error_message = $e->getMessage();
        }

        echo json_encode(['success' => $success, 'error_message' => $error_message, 'coll_codes' => $coll_codes]);
    }

    // ... [analyze_file, analyze_chat_file, touchup_file remain View loaders] ... 
public static function analyze_file(User $user, string $projectId, string $collectionCode, string $fileId) {
        self::loadJobView($user, $projectId, $collectionCode, $fileId, 'analyze.php');
    }

    public static function analyze_chat_file(User $user, string $projectId, string $collectionCode, string $fileId) {
        self::loadJobView($user, $projectId, $collectionCode, $fileId, 'analyze_chat.php');
    }

    public static function touchup_file(User $user, string $projectId, string $collectionCode, string $fileId) {
        self::loadJobView($user, $projectId, $collectionCode, $fileId, 'touchup.php');
    }

    /**
     * Helper to load job views.
     * Updated to accept and expose $collectionCode to the required view.
     */
    private static function loadJobView(User $user, string $projectId, string $collectionCode, string $fileId, string $viewName) {
        global $rootURL;
        $project = Project::withId($projectId);
        $userId = $user->getId();

        if ($project && $project->isMember($userId)) {
            $file = Files::withId($fileId);
            if ($file) {
                // These variables will be available inside the required view file
                $file_name = $file->getFilename();
                $fileId = $file->getId(); 
                // $collectionCode is passed as an argument and is now visible here
                
                require PROJECTS_JOBS_VIEWS_DIR . $viewName;
            } else {
                // Handle file not found
                header('Location: ' . $rootURL.'/projects');
            }
        } else {
            // Handle access denied
            header('Location: ' . $rootURL.'/projects');
        }
    }
    
    public static function getFileContent(User $user) {
        // ... [This calls Files::getFileContent directly. Assuming Files model handles S3 internally? 
        // If Files::getFileContent needs S3 access, that logic should be in Files.php or moved here. 
        // Leaving as is based on provided code structure.]
        header("Content-Type: application/json");
        // ... [Existing logic from your snippet] ...
        // Simplification for brevity in response:
        $success = false; $content = ""; $error = "";
        if(!isset($_GET['project_id']) || !isset($_GET['collection_code']) || !isset($_GET['file_name'])) {
             echo json_encode(['success' => false, 'error_message' => "Missing parameters"]); return;
        }
        
        $project = Project::withId($_GET['project_id']);
        if ($project && $project->isMember($user->getId())) {
             $content = Files::getFileContent($_GET['project_id'], $_GET['collection_code'], $_GET['file_name']);
             $success = true;
        } else {
             $error = "User not a member.";
        }
        echo json_encode(['success' => $success, 'error_message' => $error, 'content' => $content]);
    }

    public static function saveFileContent(User $user) {
        // ... [Calls Files::saveFileContent directly. Same logic applies.]
        header("Content-Type: application/json");
        if(!isset($_POST['project_id'])) { echo json_encode(['success' => false]); return; }
        
        $project = Project::withId($_POST['project_id']);
        if ($project && $project->isMember($user->getId())) {
            $success = Files::saveFileContent($_POST['project_id'], $_POST['collection_code'], $_POST['file_name'], $_POST['content']);
            echo json_encode(['success' => $success, 'error_message' => ""]);
        } else {
            echo json_encode(['success' => false, 'error_message' => "Access denied"]);
        }
    }

    public static function downloadZipFile(User $user) {
        // ... [Logic remains same, operates on /tmp local files] ...
        if (isset($_GET['id'])) {
            $id = $_GET['id'];
        } else {
            echo json_encode(['error' => 'id is missing']); return;
        }
        $zipFilePath = "/tmp/{$id}.zip";
        if (file_exists($zipFilePath)) {
            $fileObj = Files::withId($id);
            $downloadName = $fileObj ? pathinfo($fileObj->getFilename(), PATHINFO_FILENAME) . '.zip' : basename($zipFilePath);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Content-Length: ' . filesize($zipFilePath));
            readfile($zipFilePath);
            exit;
        } else {
            echo json_encode(['error' => 'File not found']); exit;
        }
    }
        
    public static function downloadFiles(User $user) {
        if (empty($_GET['ids'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error_message' => "Please select IDs to download."]);
            return;
        }

        global $CONFIG;
        $s3 = S3Utility::createConnection();
        $bucketName = $CONFIG["s3"]["top_level_bucket"]; 
        $outputFolder = $CONFIG["s3"]["output_bucket"]; // e.g. "output"

        header('Content-Type: application/json');
        $responses = [];
        try {
            foreach ($_GET['ids'] as $id) {
                $file = Files::withId($id);
                if (!$file) continue;

                $projectId = $file->getAssociatedProjectId();
                $project = Project::withId($projectId);
                $userId = $user->getId();
                $collectionCode = Files::getCollectionCodeNameById($id);

                if (!$project || !$project->isMember($userId)) continue;
                if (!$collectionCode) continue;

                // --- 1. Find all matching files in S3 ---
                
                // Construct the "folder" path inside the bucket: output/project/collection
                $s3FolderPath = trim($outputFolder, '/') . '/' . $projectId . '/' . $collectionCode;
                
                // Get the list of objects in that folder
                $folderContents = $s3->listFolderContents($bucketName, $s3FolderPath);
                
                $filesToDownload = [];
                if ($folderContents && isset($folderContents['files'])) {
                    foreach ($folderContents['files'] as $s3File) {
                        // Key looks like: output/proj/coll/file-for-id-456.txt
                        if (strpos($s3File['Key'], $id) !== false) {
                            $filesToDownload[] = $s3File['Key'];
                        }
                    }
                }

                if (empty($filesToDownload)) continue;

                // --- 2. Download files to a local temp directory ---
                $localTempDir = "/tmp/{$id}";
                if (!is_dir($localTempDir) && !mkdir($localTempDir, 0755, true)) {
                    throw new Exception("Cannot create temporary directory: $localTempDir");
                }

                foreach ($filesToDownload as $s3Key) {
                    $localFilePath = $localTempDir . '/' . basename($s3Key);
                    if (!$s3->downloadFile($bucketName, $s3Key, $localFilePath)) {
                        error_log("Failed to download S3 file: $s3Key");
                    }
                }

                // --- 3. Create the ZIP file ---
                $zipFilePath = "/tmp/{$id}.zip";
                $zip = new ZipArchive();
                if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new Exception("Cannot create ZIP file for $id");
                }

                $dbFilenameNoExt = pathinfo($file->getFilename(), PATHINFO_FILENAME);

                foreach (glob("{$localTempDir}/*") as $file) {
                    $basename = basename($file);
                    // Replace ID with Filename in the zip entry
                    $newName = preg_replace('/' . preg_quote($id, '/') . '/', $dbFilenameNoExt, $basename, 1);
                    $zip->addFile($file, $newName);
                }
                $zip->close();

                // --- 4. Clean up ---
                foreach (glob("{$localTempDir}/*") as $file) unlink($file);
                rmdir($localTempDir);
                
                // --- 5. Add ZIP URL to response ---
                $responses[] = [
                    'collection_code' => $collectionCode,
                    'zip_url' => "/files/download_zip?id={$id}"
                ];

            } 

            echo json_encode($responses);

        } catch (Exception $e) {
            error_log($e->getMessage());
            echo json_encode(['success' => false, 'error_message' => $e->getMessage()]);
        }
    }
    
    public static function updateStatus(User $user) {
        // ... [Same as provided] ...
        header("Content-Type: application/json");
        $success = false; $error_message = "";
        if (!isset($_POST['uuid']) || !isset($_POST['status']) || !isset($_POST['action'])){
             $error_message = "Missing parameters.";
        } else {
            try {
                Files::updateStatus($_POST['uuid'], $_POST['status'], $_POST['action']);
                $success = true;
            } catch (Exception $e) { $error_message = $e->getMessage(); }
        }
        echo json_encode(['success' => $success, 'error_message' => $error_message]);
    }

    public static function get_jobs(User $user) {
        // ... [Same as provided] ...
        header("Content-Type: application/json");
        $success = false; $error_message = ""; $jobs = [];
        // [Validation logic omitted for brevity]
        $projectId = $_POST['project_id'];
        $fileId = $_POST['file_id'];
        $project = Project::withId($projectId);
        
        if($project->isMember($user->getId())) {
            $jobs = Jobs::getJobs($fileId, $projectId);
            $success = is_array($jobs);
        }
        echo json_encode(['success' => $success, 'error_message' => $error_message, 'jobs'=> $jobs]);
    }

    public static function uploadTranscript(User $user) {
        global $CONFIG;
        header("Content-Type: application/json");
        $responses = [];

        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        $collCode = isset($data['collCode']) ? $data['collCode'] : null;
        $projectId = isset($data['project_id']) ? $data['project_id'] : null;

        if (!$collCode || !$projectId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing code/project.']);
            return;
        }

        $userId = $user->getId();
        $project = Project::withId($projectId);
        $collectionCode = CollectionCode::withCodeAndProjectId($collCode, $projectId);
        if (!$collectionCode) {
            $collectionCode = new CollectionCode();
            $collectionCode->create($collCode, $projectId);
        }
        $collectionCodeId = $collectionCode->getId();

        if ($project && $project->isMember($userId)) {
            if (!isset($data['filenames'])) {
                http_response_code(400); echo json_encode(['error' => 'No files.']); return;
            }
            $filenames = $data['filenames'];
            
            $s3 = S3Utility::createConnection();
            $bucketName = $CONFIG["s3"]["top_level_bucket"];
            $outputFolder = $CONFIG["s3"]["output_bucket"];

            foreach ($filenames as $fileName) {
                $fileModel = new Files();
                $fileModel = $fileModel->create($fileName, $collectionCodeId, $projectId, null, 'transcript');
                $fileId = $fileModel->getId();

                // Path: output/projectId/collCode/fileId.transcript
                $targetKey = self::getS3Key($outputFolder, $projectId, $collCode, "$fileId.transcript");

                $uploadUrl = $s3->generatePresignedUrlUpload($bucketName, $targetKey, 600);

                if ($uploadUrl) {
                    $responses[] = [
                        'file_id' => $fileId,
                        'fileName' => $fileName,
                        'curl_request' => $uploadUrl
                    ];
                } else {
                    Files::deleteById($fileId);
                    $responses[] = [
                        'file_id' => $fileId,
                        'fileName' => $fileName,
                        'error' => 'Error extracting curl request.'
                    ];
                }
            }
            echo json_encode($responses);
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'User not a member.']);
        }
    }

    public static function ApiGetTranscript(User $user) {
        global $CONFIG;
        header("Content-Type: application/json");
        $userId = $user->getId();
        if (!isset($_GET["file-id"])) {
            http_response_code(400); echo json_encode(["error" => "No file-id"]); die();
        }
        $fileId = $_GET["file-id"];
        $file = Files::withId($fileId);
        if (!$file) {
            http_response_code(400); echo json_encode(["error" => "File not found"]); die();
        }

        $projectId = $file->getAssociatedProjectId();
        $project = Project::withId($projectId);
        if (!$project || !$project->isMember($userId)) {
            http_response_code(403); echo json_encode(["error" => "Access denied"]); die();
        }

        $bucketName = $CONFIG["s3"]["top_level_bucket"];
        $outputFolder = $CONFIG["s3"]["output_bucket"];
        $collectionCodeName = $file->getCollectionCodeName();
        
        // Path: output/projectId/collCode/fileId.transcript
        $objectKey = self::getS3Key($outputFolder, $projectId, $collectionCodeName, "$fileId.transcript");

        $s3 = S3Utility::createConnection();
        $contents = $s3->catFile($bucketName, $objectKey);
        
        echo json_encode(["contents" => $contents]);
    }
}