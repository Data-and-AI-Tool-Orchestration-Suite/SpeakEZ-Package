<?php

const PLUGINS_VIEWS_DIR = __DIR__ . "/../views/plugins/";
require_once MODELS_DIR . 'Plugin.php';

class PluginsController {
    public static function index(User $user) {
        require PLUGINS_VIEWS_DIR . 'index.php';
    }

    public static function list(User $user) {
        header("Content-Type: application/json");
        try {
            $plugins = Plugin::list();
            http_response_code(200); // OK
            echo json_encode(['plugins' => $plugins]);
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
        $results = Plugin::listForDatatable($start, $length, $order_by, $order_dir, $filter);
        foreach ($results as $result) {
            $data_row = $result;
            $data_row['DT_RowId'] = $idx++;
            array_push($data, $data_row);
        }
        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => intval(Plugin::countForDatatable()),
                'recordsFiltered' => intval(Plugin::countFilteredForDatatable($filter)),
                'data' => $data,
            )
        );
    }



    public static function save(User $user) {
        header("Content-Type: application/json");
        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true);
        if (empty($postData)){
            $postData = $_POST;
        }
        
        
        if (!isset($postData['name'])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => "You must supply a plugin name"
            ]);
            die();
        } 
        if (!isset($postData['display_name'])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => "You must supply a plugin display name"
            ]);
            die();
        } 
        
        $name = $postData['name'];
        $displayName = $postData['display_name'];
        $description = $postData['description'] ?? null;
        $active = $postData['active'] ?? null;
        $id = $postData['id'] ?? null; // Only set if updating a plugin

        
            if (!$user->isAdmin()){
                http_response_code(403); // Forbidden
                echo json_encode([
                    'error' => "You must be an Admin to edit plugins"
                ]);
                die();
            }
            
            
        $exists = Plugin::withName($name);
        if ($exists && $exists->getId() !== $id) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => "A plugin with this name already exists"
            ]);
            die();
        }
    
        try {
            if ($id){ // Updating 
                $id = $postData['id'];
                $plugin = Plugin::withId($id);
                if (is_null($description)){
                    $description = $plugin->getDescription();
                }
                if (is_null($active)){
                    $active = $plugin->getActive();
                }

                $plugin = Plugin::update($id, $name, $displayName, $description, $active);
                if ($plugin) {
                    http_response_code(200); // OK
                }
            } else { // Creating
                if (is_null($description)){
                    $description = "";
                }
                if (is_null($active)){
                    $active = false;
                }
                $plugin = Plugin::create($name, $displayName, $description, $active);
                if ($plugin) {
                    http_response_code(201); // Created
                }
            }
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                'error' => $e->getMessage()
            ]);
            die();
        }
        if (!$plugin) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                'error' => "Failed to create plugin"
            ]);
            die();
        }

        echo json_encode([
            'plugin' => $plugin
        ]);
        die();
    
        
    }

    public static function updateActivation(User $user, string $id, bool $active) {
        header("Content-Type: application/json");
        
        if (!$user->isAdmin()) { 
            http_response_code(403); // Forbidden
            echo json_encode([
                'error' => "You must be a site Admin to change plugin activation"
            ]);
            die();
        }

        try {
            $id = (int) $id;
        } catch (Exception $e) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => "Invalid Plugin ID"
            ]);
            die();
        }

            
                
        $plugin = Plugin::withId($id);
        if (!$plugin){
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => "Invalid Plugin ID"
            ]);
            die();
        }
        
        try {
            $dropTables = filter_var($_GET["drop_tables"] ?? false, FILTER_VALIDATE_BOOLEAN);;
            $plugin->updateActivation($active, $dropTables);
            http_response_code(200); // OK
            echo json_encode([
                'updated' => true
            ]);
            die();
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                'error' => $e->getMessage()
            ]);
            die();
        }
    }

}