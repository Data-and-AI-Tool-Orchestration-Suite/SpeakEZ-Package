<?php

const API_KEYS_VIEWS_DIR = __DIR__ . "/../views/api-keys/";
require_once MODELS_DIR . 'APIKey.php';

class APIKeyController {
    public static function index(User $user) {
        require API_KEYS_VIEWS_DIR . 'index.php';
    }

    /**
     * List API Keys as array
     */
    public static function list(User $user) {
        header("Content-Type: application/json");
        
        try {
            $apiKeys = APIKey::list();
            http_response_code(200); // OK
            echo json_encode(['api_keys' => $apiKeys]);
            die();
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode(['error' => $e->getMessage()]);
            die();
        }
    }

    public static function listForDatatable(User $user) {
        header("Content-Type: application/json");
        $start = 0;
        if (isset($_GET['start']))
            $start = intval($_GET['start']);
        $length = 0;
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
        $userId = $user->getId();
        $results = APIKey::listForDatatable($userId, $start, $length, $order_by, $order_dir, $filter);
        foreach ($results as $result) {
            $data_row = $result;
            $data_row['DT_RowId'] = $idx++;
            array_push($data, $data_row);
        }
        // error_log(print_r($data, true));
        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => intval(APIKey::countForDatatable()),
                'recordsFiltered' => intval(APIKey::countFilteredForDatatable($filter)),
                'data' => $data,
            )
        );
    }


    /**
     * Save the API Key. Create if it doesn't exist, update if it does
     */
    public static function save(User $user) {
        header("Content-Type: application/json");
    
        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true) ?? $_POST;
    
        $apiKey = null;

        // Input validation
        if (!isset($postData['name'])) {
            http_response_code(400); // Bad Request
            echo json_encode(array(
                "error" => "You must supply a name."
            ));
            die();
        }
    
        $name = $postData['name'];
        $projectId = $postData['project_id'] ?? null;

        // Check if project exists
        if ($projectId) {
            if (!Plugin::isPluginActiveByName("projects")) {
                http_response_code(400); // Bad Request
                echo json_encode(array(
                    "error" => "Project plugins are not active for this site"
                ));
                die();
            }
            $project = Project::withId($projectId);
            if (!$project) {
                http_response_code(400); // Bad Request
                echo json_encode(array(
                    "error" => "Invalid Project ID"
                ));
                die();
            }
        }
    
        try {    
            // Handle creation or update
            if (isset($postData['id'])) {
                $id = $postData['id'];
                $apiKey = APIKey::withId($id);
    
                if (!$apiKey || $user->getId() !== $apiKey->getOwnerId()) {
                    http_response_code(403); // Forbidden
                    echo json_encode(array(
                        "error" => "You do not own the specified API Key."
                    ));
                    die();
                }
                $apiKey = APIKey::update($id, $name, $apiKey->getOwnerId());
                if ($apiKey) {
                    http_response_code(200); // OK
                }
            } else {
                $apiKey = APIKey::create($name, $user->getId());
                if ($apiKey){
                    http_response_code(201); // Created
                }
                
            }
    
            // Add project relation if applicable
            if (Plugin::isPluginActiveByName("projects") && $projectId && $apiKey) {
                $project = Project::withId($projectId);
                if ($project) {
                    $project->addRelation($apiKey->getId(), "project_api_keys", "api_key");
                }
            }
            echo json_encode(array(
                "api_key" => $apiKey
            ));
            die();
            
        } catch (Exception $e) {
            // Error handling
            http_response_code(500); // Internal Server Error
            echo json_encode(array(
                "error" => $e->getMessage()
            ));
            die();
        }
    
        
    }

    public static function delete(User $user) {
        header("Content-Type: application/json");

        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true) ?? $_POST;
        // ID not included in request
        if (!isset($postData['id'])) {
            http_response_code(400); // Bad Request
            echo json_encode(array(
                "error" => "You must supply an API Key ID to delete"
            ));
            die();
        }

        $id = $postData['id'];
        $apiKey = APIKey::withId($id);
        if (!$apiKey || $apiKey->getOwnerId() != $user->getId()) {
            http_response_code(403); // Bad Request
            echo json_encode(array(
                "error" => "You do not own this API Key"
            ));
            die();
        }
        try {
            APIKey::delete($user, $id);
            http_response_code(204); // No Content
            die();
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode(array(
                "error" => $e->getMessage()
            ));
            die();
        }
    }

}