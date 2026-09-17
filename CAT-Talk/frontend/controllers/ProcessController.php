<?php

require_once __DIR__ . '/../utilities/db.php';
include_once MODELS_DIR . 'User.php';


class ProcessController {
    public static function updateJob() {
        header("Content-Type: application/json");
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
                if (!isset($data['file_id'])){
                    $error_message = "You must provide a file ID.";
                } else if (!isset($data['job'])){
                    $error_message = "You must provide an action.";
                } else if (!isset($data['status'])) {
                    $error_message = "You must provide a status.";
                } else {
                    try {
                        $fileId = $data['file_id'];
                        // if (!substr($filename, 0, 8) != "uploads/"){
                        //     $filename = "uploads/".$filename;
                        // }

                        $file = Files::withId($fileId);
                        if (is_null($file)){
                            $error_message = "File not found";
                        } else {
                            Files::updateStatus($file->getId(), $data['status'], $data['job']);
                            $success = true;
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


    public static function getStatus(User $user) {
        header("Content-Type: application/json");
        $success = false;
        $error_message = "";
        $status = "";
        $action = "";
        $filename = "";

        if (!isset($_GET['uuid'])){
            $error_message = "You must provide a uuid.";
        } else {
            try {
                $file = Files::withId($_GET['uuid']);
                $filename = basename($file->getFilename());
                if (is_null($file)){
                    $error_message = "File not found";
                } else{
                    $fullStatus = $file->getStatus();
                    foreach ($fullStatus['status'] as $key => $value){
                        $action = $key;
                        $status = $value;
                    }
                    $success = true;
                }
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }

        $ret = array(
            'success' => $success,
            'error_message' => $error_message,
            'status' => $status,
            'action' => $action,
            'filename' => $filename
        );
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

}