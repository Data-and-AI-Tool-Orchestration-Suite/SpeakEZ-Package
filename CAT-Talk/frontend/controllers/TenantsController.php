<?php

const TENANTS_VIEWS_DIR = __DIR__ . "/../views/tenants/";
require_once MODELS_DIR . 'Tenant.php';

class TenantsController {
    public static function index(User $user) {
        require TENANTS_VIEWS_DIR . 'index.php';
    }

    /** 
     * View for managing tenants
     */
    public static function manage(User $user, string $tenantId) {
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
        $users = $tenant->getUsers();                
        require TENANTS_VIEWS_DIR . 'manage/manage.php';
    }

    /**
     * Switch tenant to ID specified in POST if possible
     */
    public static function switchTenant(User $user){
        header("Content-Type: application/json");
        global $rootURL;

        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true) ?? $_POST;

        $userId = $user->getId();
        $tenantId = $postData["tenant_id"] ?? null;
        // Handle if tenant_id not set
        if (!$tenantId) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "You must include a tenant ID in your request",
            ]);
            die();
        }

        $tenant = Tenant::withId($tenantId);
        // Handle if tenant does not exist or user not a member
        if (!$tenant || !$tenant->isMember($userId)) {
            http_response_code(403); // Forbidden
            echo json_encode([
                "error" => "You are not a member of a tenant with this ID",
            ]);
            die();
        }

        $tenant->setCurrentTenant($userId);

        http_response_code(200); // OK
        echo json_encode([
            "tenant_id" => Tenant::getCurrentTenant($userId),
        ]);
        die();
    }

    /**
     * General Tenant Handling
     */

    public static function getTenants(User $user) {
        header("Content-Type: application/json");

        try {
            $tenants = Tenant::getTenants($user);
            http_response_code(200); // OK
            echo json_encode([
                "tenants" => $tenants,
            ]);
            die();
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                "error" => $e->getMessage(),
            ]);
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
        $userId = $user->getId();
        $results = Tenant::listForDatatable($start, $length, $order_by, $order_dir, $filter);
        foreach ($results as $result) {
            $data_row = $result->jsonSerialize();
            $data_row['DT_RowId'] = $idx++;
            array_push($data, $data_row);
        }
        // error_log(print_r($data, true));
        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => intval(Tenant::countForDatatable()),
                'recordsFiltered' => intval(Tenant::countFilteredForDatatable($filter)),
                'data' => $data,
            )
        );
    }


    /**
     * Tenant User Handling
     */

    public static function listByUserForDatatable(User $user) {
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
        $results = Tenant::listForDatatable($start, $length, $order_by, $order_dir, $filter);
        foreach ($results as $result) {
            $data_row = $result->jsonSerialize();
            $data_row['DT_RowId'] = $idx++;
            array_push($data, $data_row);
        }
        // error_log(print_r($data, true));
        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => intval(Tenant::countForDatatable()),
                'recordsFiltered' => intval(Tenant::countFilteredForDatatable($filter)),
                'data' => $data,
            )
        );
    }

    public static function listUsers(User $user, string $tenantId) {
        header("Content-Type: application/json");

        $tenant = Tenant::withId($tenantId);
        if ($tenant->hasRole($user->getId(), "Admin") 
            || $user->isAdmin() // Allows site admins to view any tenant's users
        ){
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

            $results = Tenant::listUsers($tenantId, $start, $length, $order_by, $order_dir, $filter);
            foreach ($results as $result) {
                $data_row = $result;
                $data_row['DT_RowId'] = $idx++;
                array_push($data, $data_row);
            }
            // error_log(print_r($data, true));
            echo json_encode(
                array(
                    'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                    'recordsTotal' => intval(Tenant::countUsersForDatatable($tenantId)),
                    'recordsFiltered' => intval(Tenant::countFilteredUsersForDatatable($tenantId, $filter)),
                    'data' => $data,
                )
            );
        } else {
            echo json_encode(
                array(
                    'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                    'recordsTotal' => 0,
                    'recordsFiltered' => 0,
                    'data' => array(),
                )
            );
        }
    }

    /**
     * Adds a users to the specified tenant
     */
    public static function submitUser(User $user, string $tenantId){
        // Set default headers
        header("Content-Type: application/json");
        // Parse incoming JSON or POST data
        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true) ?? $_POST;
        
        $tenant = Tenant::withId($tenantId);
        // Check if tenant exists
        if (!$tenant) {
            http_response_code(404); // Not Found
            echo json_encode([
                "error" => "Tenant with specified ID does not exist",
            ]);
            die();
        }
        // Check if user has admin role in the tenant
        if (!$tenant->hasRole($user->getId(), "Admin") 
            // Allows user to add members to any tenant if site admin. Comment to test default behavior
            && !$user->isAdmin() 
        ) {
            http_response_code(403); // Forbidden
            echo json_encode([
                "error" => "You must be the tenant admin to add a user",
            ]);
            die();
        }
        // Check if user has admin role in the tenant
        if (!$tenant->hasRole($user->getId(), "Admin") 
            // Allows user to add members to any tenant if site admin. Comment to test default behavior
            && !$user->isAdmin() 
        ) {
            http_response_code(403); // Forbidden
            echo json_encode([
                "error" => "You must be the tenant admin to add a user",
            ]);
            die();
        }

        // Email not set
        if (!isset($postData['email'])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "Must include a valid email address",
            ]);
            die();
        }

        // Roles not set
        if (!isset($postData['roles'])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "Must include user's roles",
            ]);
            die();
        }

        $id = $postData['id'] ?? null;
        $email = $postData['email'];
        $roles = $postData['roles'];

        $roleNames = [];
        foreach ($roles as $roleId) {
            $roleName = $tenant->getRoles()[$roleId] ?? null;
            if ($roleName) {
                $roleNames[] = $roleName;
            } else {
                http_response_code(400); // Bad Request
                echo json_encode([
                    "error" => "Invalid role submitted: $roleId",
                ]);
                die();
            }
        }
        if (sizeof($roleNames) < 1) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "You must submit at least one role for the user",
            ]);
            die();
        }


        try {
            // If ID set, try to update user
            // Otherwise, check if user exists with given EPPN or email
            // Throw an error if already exists, otherwise add them.
            if ($id){
                $submittedUser = User::withId($id);
                if ($submittedUser){
                    $submittedUser = User::update($id, $email, $submittedUser->getRoles());
                }
            } else {
                // Retrieve the submitted user from the given email. Prefer EPPN, but check email as well
                $submittedUser = User::withEPPN($email) ?? User::withEmail($email);
                // Create user if not already set
                if (!$submittedUser) {
                    $submittedUser = User::createBeforeLogin($email, [User::getRoleIdFromName("User")]);
                }
            }
            
            // Set tenant roles for user
            // May want to rewrite this because this was processed earlier
            if ($submittedUser){
                $roleNames = [];
                foreach ($roles as $roleId) {
                    $roleName = $tenant->getRoles()[$roleId] ?? null;
                    if ($roleName) {
                        $roleNames[] = $roleName;
                    }
                }
                try {
                    $tenant->setUserRoles($submittedUser->getId(), $roleNames);
                } catch (Exception $e) {
                    error_log(print_r($e, true));
                }
            }
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                "error" => $e->getMessage(),
            ]);
            die();
        }

        http_response_code(200); // OK
        echo json_encode([
            'user' => $submittedUser
        ]);
        die();
    }

    /**
     * Removes a users from the specified tenant
     */
    public static function removeUser(User $user, string $tenantId){
        // Set default headers
        header("Content-Type: application/json");
        // Parse incoming JSON or POST data
        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true) ?? $_POST;
        
        $tenant = Tenant::withId($tenantId);

        // Check if tenant exists
        if (!$tenant) {
            http_response_code(404); // Not Found
            echo json_encode([
                "error" => "Tenant with specified ID does not exist",
            ]);
            die();
        }

        // Check if user has admin role in the tenant
        if (!$tenant->hasRole($user->getId(), "Admin")) {
            http_response_code(403); // Forbidden
            echo json_encode([
                "error" => "You must be the tenant admin to remove a user",
            ]);
            die();
        }

        // Check if user ID for the user to remove is provided
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

        // Check if the user is a user of the tenant
        if (!$tenant->isMember($removeUserId)) {
            http_response_code(409); // Conflict
            echo json_encode([
                "error" => "The specified user is not a user of this tenant",
            ]);
            die();
        }

        // Remove the user from the tenant
        try {
            $tenant->removeUser($removeUserId);
            // Respond with success
            http_response_code(201); // No Content
            die();
        } catch (Exception $e) {
            // Respond with success
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => $e->getMessage(),
            ]);
            die();
        }
        
        
    }


    public static function submit(User $user) {
        header("Content-Type: application/json");
    
        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true) ?? $_POST;
    
        if (empty($postData['name'])) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => "You must supply a tenant name"]);
            die();
        }
    
        $name = $postData['name'];
        $canSelfManage = $postData["can_self_manage"] ?? null;
        if ($canSelfManage === "true"){
            $canSelfManage = true;
        } else if ($canSelfManage === "false") {
            $canSelfManage = false;
        } else if (gettype($canSelfManage) === "string") {
            $canSelfManage = false;
        }
        // Handle update if `id` is provided
        if (!empty($postData['id'])) {
            $tenantId = $postData['id'];
            $tenant = Tenant::withId($tenantId);
    
            if (!$tenant) {
                http_response_code(404); // Not Found
                echo json_encode(['error' => "Tenant with specified ID does not exist"]);
                die();
            }
    
            if (!$user->hasRole("Admin")) {
                http_response_code(403); // Forbidden
                echo json_encode(['error' => "You are not an Administrator of the site"]);
                die();
            }
            
            if (is_null($canSelfManage)) {
                $canSelfManage = $tenant->getCanSelfManage();
            }
    
            try {
                $updatedTenant = Tenant::update($tenantId, $name, $canSelfManage);
    
                if (!$updatedTenant) {
                    throw new Exception("Failed to update the tenant");
                }
    
                http_response_code(200); // OK
                echo json_encode($updatedTenant->jsonSerialize());
                die();
    
            } catch (Exception $e) {
                http_response_code(500); // Internal Server Error
                error_log(print_r($e, true));
                echo json_encode(['error' => $e->getMessage()]);
                die();
            }
        }
    
        // Handle creation if `id` is not provided
        try {
            if (is_null($canSelfManage)) {
                $canSelfManage = false;
            }

            $createdTenant = Tenant::create($name, $canSelfManage);
    
            if (!$createdTenant) {
                throw new Exception("Failed to create the tenant");
            }
    
            http_response_code(201); // Created
            echo json_encode($createdTenant->jsonSerialize());
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            error_log(print_r($e, true));
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    

    public static function delete(User $user) {
        header("Content-Type: application/json");
    
        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true) ?? $_POST;
    
        if (empty($postData['id'])) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => "You must supply a tenant id to delete"]);
            die();
        }
    
        $id = $postData['id'];
        $tenant = Tenant::withId($id);
    
        if (!$tenant) {
            http_response_code(400); // Bad Requests
            echo json_encode(['error' => "Tenant with specified ID does not exist"]);
            die();
        }
    
        if (!$user->hasRole("Admin")) {
            http_response_code(403); // Forbidden
            echo json_encode(['error' => "You are not an Administrator of the site"]);
            die();
        }
    
        try {
            Tenant::delete($user, $id);
            http_response_code(204); // No Content
            die();
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Get the roles associated with a tenant
     */
    public static function getRoles(User $user, string $tenantId) {
        header("Content-Type: application/json");
        
        // Check tenant exists
        $tenant = Tenant::withId($tenantId);
        if (!$tenant) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => "Tenant with specified ID does not exist"]);
            die();
        }

        try {
            $roles = $tenant->getRoles();
            http_response_code(200); // OK
            echo json_encode(['roles' => $roles], JSON_FORCE_OBJECT);
            die();
        } catch (Exception $e) {
            error_log(print_r($e, true));
            http_response_code(500); // Internal Server Error
            echo json_encode(['error' => "Internal Server Error: {$e->getMessage()}"]);
            die();
        }
    }

    /**
     * Save the submitted styles to the tenant
     */
    public static function saveStyles(User $user, string $tenantId) {
        header("Content-Type: application/json");
    
        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true) ?? $_POST;

        // Check tenant exists
        $tenant = Tenant::withId($tenantId);
        if (!$tenant) {
            http_response_code(400); // Bad Request
            echo json_encode(['error' => "Tenant with specified ID does not exist"]);
            die();
        }

        try {
            foreach ($postData as $styleName => $styleValue) {
                if (!in_array($styleName, Tenant::getValidStyles())){
                    http_response_code(400); // Bad Request
                    echo json_encode(['error' => "Invalid Style Name: $styleName"]);
                    die();
                }
            }

            foreach ($postData as $styleName => $styleValue) {
                $tenant->setStyle($styleName, $styleValue);
            }

            
            http_response_code(204); // No Content
            die();
        } catch (Exception $e) {
            error_log(print_r($e, true));
            http_response_code(500); // Internal Server Error
            echo json_encode(['error' => "Internal Server Error: {$e->getMessage()}"]);
            die();
        }
    }

    public static function metrics(User $user, string $tenantId) {
        global $rootURL;
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
        require TENANTS_VIEWS_DIR . 'metrics.php';
    }
}