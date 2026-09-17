<?php

const PROJECTS_VIEWS_DIR = __DIR__ . "/../views/projects/";
const PROJECTS_JOBS_VIEWS_DIR = __DIR__ . "/../views/projects/jobs/";
require_once MODELS_DIR . 'Project.php';
require_once MODELS_DIR . 'APIKey.php';

class ProjectsController {
    public static function index(User $user) {
        error_log("ProjectsController::index called");
        require PROJECTS_VIEWS_DIR . 'index.php';
    }

    public static function project_jobs_index(User $user, string $projectId) {
        $userId = $user->getId();
        $project = Project::withId($projectId);
        if ($project){
            $members = $project->getMembers();
            if (isset($members[$userId])){
                require PROJECTS_JOBS_VIEWS_DIR . 'index.php';
            } else {
                header("Location: /projects");
            }
        } else {
            header("Location: /projects");
        }
    }


    public static function details(User $user, string $projectId) {
        $userId = $user->getId();
        $project = Project::withId($projectId);
        if ($project){
            if ($project->isMember($user->getId())){
                
                $members = $project->getMembers();
                
                
                require PROJECTS_VIEWS_DIR . 'details/details.php';


            } else {
                header("Location: /projects");
            }
        } else {
            header("Location: /projects");
        }
        
    }

    public static function dashboard(User $user, string $projectId) {
        $userId = $user->getId();
        $project = Project::withId($projectId);
        if ($project){
            // $members = $project->getMembers();
            if ($project->isMember($userId)){
                require PROJECTS_VIEWS_DIR . 'dashboard.php';
            } else {
                header("Location: /projects");
            }
        } else {
            header("Location: /projects");
        }
    }


    /**
     * General Project Handling
     */

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
        $userId = $user->getId();
        $results = Project::listForDatatable($userId, $start, $length, $order_by, $order_dir, $filter);
        foreach ($results as $result) {
            $data_row = $result->jsonSerialize();
            $data_row['DT_RowId'] = $idx++;
            array_push($data, $data_row);
        }
        // error_log(print_r($data, true));
        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => intval(Project::countForDatatable($userId)),
                'recordsFiltered' => intval(Project::countFilteredForDatatable($userId, $filter)),
                'data' => $data,
            )
        );
    }


    /**
     * Project Member Handling
     */

    public static function listMembers(User $user, string $projectId, bool $unaffiliated=false) {
        header("Content-Type: application/json");

        $project = Project::withId($projectId);
        if ($project->isMember($user->getId())){
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

            $results = Project::listMembers($projectId, $unaffiliated, $start, $length, $order_by, $order_dir, $filter);
            foreach ($results as $result) {
                $data_row = $result->jsonSerialize();
                $data_row['DT_RowId'] = $idx++;
                array_push($data, $data_row);
            }
            // error_log(print_r($data, true));
            echo json_encode(
                array(
                    'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                    'recordsTotal' => intval(Project::countMembersForDatatable($projectId, $unaffiliated)),
                    'recordsFiltered' => intval(Project::countFilteredMembersForDatatable($projectId, $unaffiliated, $filter)),
                    'data' => $data,
                )
            );
        } else {
            echo json_encode(
                array(
                    'draw' => 0,
                    'recordsTotal' => 0,
                    'recordsFiltered' => 0,
                    'data' => array(),
                )
            );
        }
    }

    public static function addMember(User $user, string $projectId){
        // Set default headers
        header("Content-Type: application/json");
        // Parse incoming JSON or POST data
        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true) ?? $_POST;
        
        $project = Project::withId($projectId);
        // Check if project exists
        if (!$project) {
            http_response_code(404); // Not Found
            echo json_encode([
                "error" => "Project with specified ID does not exist",
            ]);
            die();
        }
        // Check if user has admin role in the project
        if (!$project->isRole($user->getId(), "Admin")) {
            http_response_code(403); // Forbidden
            echo json_encode([
                "error" => "You must be the project admin to add a member",
            ]);
            die();
        }
        // Check if user ID for the new member is provided
        if (empty($postData["user_id"])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "You must include a new user ID in your request",
            ]);
            die();
        }
        $newUserId = $postData["user_id"];
        $newUser = User::withId($newUserId);

        // Check if the new user exists
        if (!$newUser) {
            http_response_code(404); // Not Found
            echo json_encode([
                "error" => "No user exists with the specified ID",
            ]);
            die();
        }
        // Check if the user is already a member of the project
        if ($project->isMember($newUserId)) {
            http_response_code(409); // Conflict
            echo json_encode([
                "error" => "The specified user is already a member of this project",
            ]);
            die();
        }
        // Add the new member to the project
        $project->saveMember($newUserId, "Member");
        // Respond with No Content, member added
        http_response_code(204); // No Content
        die();
    }


    public static function removeMember(User $user, string $projectId){
        // Set default headers
        header("Content-Type: application/json");
        // Parse incoming JSON or POST data
        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true) ?? $_POST;
        
        $project = Project::withId($projectId);

        // Check if project exists
        if (!$project) {
            http_response_code(404); // Not Found
            echo json_encode([
                "error" => "Project with specified ID does not exist",
            ]);
            die();
        }

        // Check if user has admin role in the project
        if (!$project->isRole($user->getId(), "Admin")) {
            http_response_code(403); // Forbidden
            echo json_encode([
                "error" => "You must be the project admin to remove a member",
            ]);
            die();
        }

        // Check if user ID for the member to remove is provided
        if (empty($postData["user_id"])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "You must include a user ID in your request",
            ]);
            die();
        }

        $removeUserId = $postData["user_id"];
        $removeUser = User::withId($removeUserId);

        // Check if the user to be removed exists
        if (!$removeUser) {
            http_response_code(404); // Not Found
            echo json_encode([
                "error" => "No user exists with the specified ID",
            ]);
            die();
        }

        // Check if the user is a member of the project
        if (!$project->isMember($removeUserId)) {
            http_response_code(409); // Conflict
            echo json_encode([
                "error" => "The specified user is not a member of this project",
            ]);
            die();
        }

        // Remove the member from the project
        $project->removeMember($removeUserId);
        // Respond with No Content, member removed
        http_response_code(204); // No Content
        die();
    }


    /**
     * Abstracted Project Relation Handling
     */

    public static function listRelation(
        User $user, 
        string $projectId, 
        string $linkTableName, 
        string $linkTableRelatedField, 
        array $filterFields, 
        string $relatedTableName, 
        string $relatedTableReferenceField="id", 
        ) {
        header("Content-Type: application/json");
        $project = Project::withId($projectId);

        if ($project->isMember($user->getId())){
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
            
            $results = Project::listRelation(
                $projectId, 
                $linkTableName,
                $linkTableRelatedField,
                $filterFields,
                $relatedTableName,
                $relatedTableReferenceField,
                $start, 
                $length, 
                $order_by, 
                $order_dir, 
                $filter
            );
            foreach ($results as $result) {
                $data_row = $result;
                $data_row['DT_RowId'] = $idx++;
                array_push($data, $data_row);
            }

            echo json_encode(
                array(
                    'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                    'recordsTotal' => intval(Project::countRelationForDatatable($projectId, $linkTableName)),
                    'recordsFiltered' => intval(Project::countFilteredRelationForDatatable(
                                                            $projectId, 
                                                            $linkTableName, 
                                                            $linkTableRelatedField, 
                                                            $filter, 
                                                            $filterFields, 
                                                            $relatedTableName, 
                                                            $relatedTableReferenceField
                                                        )),
                    'data' => $data,
                )
            );
        }
        else echo json_encode(
            array(
                'draw' => 0,
                'recordsTotal' => intval(0),
                'recordsFiltered' => intval(0),
                'data' => array("error" => "You are not a member of this project"),
            )
        );
    }


    public static function addRelation(
        User $user,
        string $projectId,
        array $postData,
        $linkTableRelatedValue,
        string $linkTableName,
        string $linkTableRelatedField
    ) {
        // Set default headers
        header("Content-Type: application/json");
    
        $project = Project::withId($projectId);
    
        // Check if project exists
        if (!$project) {
            http_response_code(404); // Not Found
            echo json_encode([
                "error" => "Project with specified ID does not exist",
            ]);
            die();
        }
    
        // Check if the user is a member of the project
        if (!$project->isMember($user->getId())) {
            http_response_code(403); // Forbidden
            echo json_encode([
                "error" => "You are not a member of a project with the specified ID",
            ]);
            die();
        }
    
        // Attempt to add the relation
        try {
            $project->addRelation($linkTableRelatedValue, $linkTableName, $linkTableRelatedField);
            http_response_code(204); // No Content
            die();
        } catch (PDOException $e) {
            // Handle duplicate entry or constraint errors
            http_response_code(409); // Conflict
            echo json_encode([
                "error" => "This project already owns the specified object",
            ]);
            die();
        }
    }

    public static function removeRelation(
        User $user,
        string $projectId,
        array $postData,
        $linkTableRelatedValue,
        string $linkTableName,
        string $linkTableRelatedField
    ) {
        // Set default headers
        header("Content-Type: application/json");
    
        // Validate project_id in the request
        if (empty($postData["project_id"])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "You must include a project ID in your request",
            ]);
            die();
        }
    
        $projectId = $postData["project_id"];
        $project = Project::withId($projectId);
    
        // Check if project exists
        if (!$project) {
            http_response_code(404); // Not Found
            echo json_encode([
                "error" => "Project with specified ID does not exist",
            ]);
            die();
        }
    
        // Check if the user is a member of the project
        if (!$project->isMember($user->getId())) {
            http_response_code(403); // Forbidden
            echo json_encode([
                "error" => "You are not a member of a project with the specified ID",
            ]);
            die();
        }
    
        // Check if the relation exists
        if (!$project->checkRelationExists($linkTableRelatedValue, $linkTableName, $linkTableRelatedField)) {
            http_response_code(404); // Not Found
            echo json_encode([
                "error" => "The specified object is not a member of this project",
            ]);
            die();
        }
    
        // Attempt to remove the relation
        try {
            $project->removeRelation($linkTableRelatedValue, $linkTableName, $linkTableRelatedField);
            http_response_code(204); // No Content
            die();
        } catch (PDOException $e) {
            error_log(print_r($e, true));
            http_response_code(500); // Internal Server Error
            echo json_encode([
                "error" => "An error occurred while removing the relation",
            ]);
            die();
        }
    }
    


    



    public static function save(User $user) {
        header("Content-Type: application/json");
    
        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true) ?? $_POST;
    
        if (empty($postData['name'])) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => "You must supply a project name"]);
            die();
        }
    
        $name = $postData['name'];
    
        // Handle update if `id` is provided
        if (!empty($postData['id'])) {
            $projectId = $postData['id'];
            $project = Project::withId($projectId);
    
            if (!$project) {
                http_response_code(404); // Not Found
                echo json_encode(['error' => "Project with specified ID does not exist"]);
                die();
            }
    
            if (!$project->isRole($user->getId(), "Admin")) {
                http_response_code(403); // Forbidden
                echo json_encode(['error' => "You are not an Administrator of the specified project"]);
                die();
            }
    
            try {
                $updatedProject = Project::update($projectId, $name);
    
                if (!$updatedProject) {
                    throw new Exception("Failed to update the project");
                }
    
                http_response_code(200); // OK
                echo json_encode(['project' => $updatedProject]);
                die();
    
            } catch (Exception $e) {
                http_response_code(500); // Internal Server Error
                echo json_encode(['error' => $e->getMessage()]);
                die();
            }
        }
    
        // Handle creation if `id` is not provided
        try {
            $createdProject = Project::create($name, $user->getId());
    
            if (!$createdProject) {
                throw new Exception("Failed to create the project");
            }
    
            http_response_code(201); // Created
            echo json_encode(['project' => $createdProject]);
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    

    public static function delete(User $user) {
        header("Content-Type: application/json");
    
        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true) ?? $_POST;
    
        if (empty($postData['id'])) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => "You must supply a project id to delete"]);
            die();
        }
    
        $id = $postData['id'];
        $project = Project::withId($id);
    
        if (!$project) {
            http_response_code(404); // Not Found
            echo json_encode(['error' => "Project with specified ID does not exist"]);
            die();
        }
    
        if (!$project->isRole($user->getId(), "Admin")) {
            http_response_code(403); // Forbidden
            echo json_encode(['error' => "You are not an Administrator of the specified project"]);
            die();
        }
    
        try {
            Project::delete($user, $id);
            http_response_code(204); // No Content
            die();
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode(['error' => $e->getMessage()]);
            die();
        }
    }



    public static function listCollections(User $user, string $projectId) {
        error_log("GETTING HERE");
        header("Content-Type: application/json");
        $success = false;
        $collection_codes = null;
        $error_message = null;

        $userId = $user->getId();
        $project = Project::withId($projectId);
        if ($project){
            if ($project->isMember($userId)){
                error_log("projectId: " . $projectId);
                $results = Project::listCollections($projectId);
                $success = true;
            } else {
                $error_message = "User does not belong to a project with id " . $projectId . ".";
            }
        } else {
            $error_message = "Project with id " . $projectId . " does not exist.";
        }

        $ret = array('success' => $success, 'error_message' => $error_message, 'collection_codes' => $results);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function getRunningJobs(User $user, string $projectId) {
        header("Content-Type: application/json");
        $success = false;
        $collection_codes = null;
        $error_message = null;
        $results = null;

        $userId = $user->getId();
        $project = Project::withId($projectId);
        if ($project && $project->isMember($user->getId())){
            $results = Project::getRunningJobs($projectId);
            $success = true;
        } else {
            $error_message = "User does not belong to a project with id " . $projectId . ".";
        }

        $ret = array('success' => $success, 'error_message' => $error_message, 'jobs' => $results);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }

    public static function ccDashboard(User $user, string $projectId, string $collectionCode) {
        $userId = $user->getId();
        $project = Project::withId($projectId);
        if ($project){
            $members = $project->getMembers();
            $collectionCodes = $project->getCollectionCodes();
            if ($project->isMember($userId) && in_array($collectionCode, $collectionCodes)){
                require PROJECTS_JOBS_VIEWS_DIR . 'index.php';
            } else {
                header("Location: /projects");
            }
        } else {
            header("Location: /projects");
        }
    }


    public static function list(User $user) {
        header("Content-Type: application/json");
        $success = false;
        $projects = null;
        $error_message = null;
        try {
            $projects = Project::list();
            $success = true;
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
        $ret = array('success' => $success, 'error_message' => $error_message, 'projects' => $projects);
        echo json_encode((object) array_filter($ret, function($value) { return $value !== null; }));
    }



    public static function listAPIKeys(User $user, $projectId) {
        header("Content-Type: application/json");
        $project = Project::withId($projectId);

        if (isset($project->getMembers()[$user->getId()])){
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
            $results = Project::listAPIKeys($projectId, $userId, $start, $length, $order_by, $order_dir, $filter);
            foreach ($results as $result) {
                $data_row = $result;
                $data_row['DT_RowId'] = $idx++;
                array_push($data, $data_row);
            }
            // error_log(print_r($data, true));
            echo json_encode(
                array(
                    'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                    'recordsTotal' => intval(Project::countForDatatable()),
                    'recordsFiltered' => intval(Project::countFilteredForDatatable($filter)),
                    'data' => $data,
                )
            );
        }
        else echo json_encode(
            array(
                'draw' => 0,
                'recordsTotal' => intval(0),
                'recordsFiltered' => intval(0),
                'data' => array(),
            )
        );
    }

    public static function ApiListProjects(User $user) {
        header("Content-Type: application/json");
        $userId = $user->getId();
        $projects = Project::apiListProjects($userId); // all projects a user belongs to
        echo json_encode($projects);
    }

    public static function ApiListProjectCollections(User $user) {
        header("Content-Type: application/json");
        $userId = $user->getId();
        if (!isset($_GET['project_id'])) {
            http_response_code(400);
            echo json_encode(["error" => "No project_id included"]);
            die();
        }
        $projectId = $_GET["project_id"];
        $project = Project::withId($projectId);
        if (!$project || !$project->isMember($userId)) {
            http_response_code(400);
            echo json_encode(["error" => "User not a member of specified project"]);
            die();
        }
        $collections = Project::apiListProjectCollections($projectId);
        echo json_encode($collections);
    }

    public static function ApiListFiles(User $user) {
        header("Content-Type: application/json");
        $userId = $user->getId();
        if (!isset($_GET['project-id'])) {
            http_response_code(400);
            echo json_encode(["error" => "No project-id included"]);
            die();
        }
        $projectId = $_GET["project-id"];
        $project = Project::withId($projectId);
        if (!$project || !$project->isMember($userId)) {
            http_response_code(400);
            echo json_encode(["error" => "User not a member of specified project"]);
            die();
        }
        $files = Project::apiListProjectFiles($projectId);
        echo json_encode($files);
    }
}
