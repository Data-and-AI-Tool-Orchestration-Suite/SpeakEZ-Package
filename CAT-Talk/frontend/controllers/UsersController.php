<?php

const USERS_VIEWS_DIR = __DIR__ . '/../views/users/';

require_once MODELS_DIR . 'User.php';
require_once MODELS_DIR . 'UserSession.php';

class UsersController {
    public static function index(User $user): void {
        require USERS_VIEWS_DIR . 'index.php';
    }

    public static function listForDatatable(User $user): void {
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
        $results = User::listForDatatable($start, $length, $order_by, $order_dir, $filter);
        foreach ($results as $result) {
            $data_row = $result;
            $roles = array();
            foreach ($data_row["roles"] as $role){
                $roles[$role] = User::getRoleNameFromId($role);
            }
            $data_row["roles"] = json_encode($roles, true);

            $data_row['DT_RowId'] = $idx++;
            $data[] = $data_row; // new syntax for array_push()
        }
        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => User::countForDatatable(),
                'recordsFiltered' => User::countFilteredForDatatable($filter),
                'data' => $data,
            )
        );
    }


   /**
     * Create a new user on the site. Set the user's roles and 
     * tenants they are a member of.
     */
    public static function submit(User $user): void {
        header("Content-Type: application/json");
        $submittedUsers = [];
        $errors = [];
        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true) ?? $_POST;

        // Email not set
        if (!isset($postData['email'])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "Must include a valid email address or array of email addresses",
            ]);
            die();
        }

        // Roles not set
        if (!isset($postData['roles']) || empty($postData['roles'])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "Must include user's roles",
            ]);
            die();
        }

        // Roles not set
        if (!isset($postData['tenants']) || empty($postData['tenants'])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "Must include user's tenants",
            ]);
            die();
        }

        // Convert single email to array for consistent processing
        $emails = is_array($postData['email']) ? $postData['email'] : [$postData['email']];
        $ids = isset($postData['id']) ? (is_array($postData['id']) ? $postData['id'] : [$postData['id']]) : [];
        $roles = $postData['roles'];
        $tenants = $postData["tenants"];

        // Validate tenants structure if provided
        if ($tenants) {
            if (!is_array($tenants)) {
                http_response_code(400); // Bad Request
                echo json_encode([
                    "error" => "Tenants must be a dictionary of tenant IDs mapped to a list of user roles",
                ]);
                die();
            }

            $hasAtLeastOneRole = false;
            foreach ($tenants as $tenantId => $tenantRoles) {
                if (!is_array($tenantRoles)) {
                    http_response_code(400); // Bad Request
                    echo json_encode([
                        "error" => "Tenant ID $tenantId must map to an array of role IDs",
                    ]);
                    die();
                }
                if (!empty($tenantRoles)) {
                    $hasAtLeastOneRole = true;
                }
            }

            if (!$hasAtLeastOneRole) {
                http_response_code(400); // Bad Request
                echo json_encode([
                    "error" => "At least one tenant must have at least one role",
                ]);
                die();
            }

            foreach ($tenants as $tenantId => $tenantRoles) {
                $currentTenant = Tenant::withId($tenantId);
                if (!$currentTenant) {
                    http_response_code(400); // Bad Request
                    echo json_encode([
                        "error" => "No tenant exists with ID $tenantId",
                    ]);
                    die();
                } 
                foreach ($tenantRoles as $roleId) {
                    $roleName = $currentTenant->getRoles()[$roleId] ?? null;
                    if (!$roleName) {
                        http_response_code(400); // Bad Request
                        echo json_encode([
                            "error" => "$roleId is not a valid role on " . $currentTenant->getName(),
                        ]);
                        die();
                    }
                }
            }
        }

        // Process each email
        foreach ($emails as $index => $email) {
            $submittedUser = null;
            $id = isset($ids[$index]) ? $ids[$index] : null;
            
            try {
                // If ID set, try to update user
                // Otherwise, check if user exists with given EPPN or email
                // Throw an error if already exists, otherwise add them.
                if ($id) {
                    $submittedUser = User::withId($id);
                    if ($submittedUser) {
                        $submittedUser = User::update($id, $email, $roles);
                    }
                } else {
                    // Retrieve the submitted user from the given email. Prefer EPPN, but check email as well
                    $submittedUser = User::withEPPN($email) ?? User::withEmail($email);
                    if ($submittedUser) {
                        $errors[] = [
                            "email" => $email,
                            "error" => "User $email already exists"
                        ];
                        continue; // Skip to next email instead of dying
                    }
                    $submittedUser = User::createBeforeLogin($email, $roles);
                }
                
                // Set tenants for user
                if ($submittedUser && $tenants && is_array($tenants)) {
                    foreach ($tenants as $tenantId => $tenantRoles) {
                        $currentTenant = Tenant::withId($tenantId);
                        if ($currentTenant) {
                            $roleNames = [];
                            foreach ($tenantRoles as $roleId) {
                                $roleName = $currentTenant->getRoles()[$roleId] ?? null;
                                if ($roleName) {
                                    $roleNames[] = $roleName;
                                }
                            }
                            try {
                                $currentTenant->setUserRoles($submittedUser->getId(), $roleNames);
                            } catch (Exception $e) {
                                error_log(print_r($e, true));
                                $errors[] = [
                                    "email" => $email,
                                    "error" => "Failed to set tenant roles: " . $e->getMessage()
                                ];
                            }
                        }
                    }
                }
                
                if ($submittedUser) {
                    $submittedUsers[] = $submittedUser;
                }
                
            } catch (Exception $e) {
                $errors[] = [
                    "email" => $email,
                    "error" => $e->getMessage()
                ];
            }
        }

        // Determine response based on results
        if (empty($submittedUsers) && !empty($errors)) {
            // All failed
            http_response_code(400); // Bad Request
            if (count($errors) === 1) {
                echo json_encode([
                    "error" => $errors[0]["error"] ?? "Failed to add user"
                ]);
            } else {
                echo json_encode([
                    "error" => "Failed to process all users",
                    "details" => $errors
                ]);
            }
        } elseif (!empty($errors)) {
            // Partial success
            http_response_code(207); // Multi-Status
            echo json_encode([
                "users" => $submittedUsers,
                "errors" => $errors
            ]);
        } else {
            // All successful
            http_response_code(200); // OK
            echo json_encode([
                "users" => count($submittedUsers) === 1 ? $submittedUsers[0] : $submittedUsers
            ]);
        }
        die();
    }

    public static function getRoles(User $user): void {
        header("Content-Type: application/json");
        try {
            $roles = User::getAllRoles();
            http_response_code(200); // OK
            echo json_encode([
                "roles" => $roles,
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

    /**
     * Delete the user specified in the POST from the site
     */
    public static function deleteUser(User $user): void {
        header("Content-Type: application/json");
        // If id param not set
        if (empty($_POST['id'])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "User ID not found.",
            ]);
            die();
        }

        $id = $_POST['id'];
        $deleteUser = User::withId($id);
        // If provided user does not exist
        if (!$deleteUser) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "Invalid User ID",
            ]);
            die();
        }
        // If user is not admin, they cannot delete users
        if (!$user->isAdmin()) {
            http_response_code(403); // Forbidden
            echo json_encode([
                "error" => "You must be a site Admin to delete a user",
            ]);
            die();
        }
        // Attempt to delete
        $status = User::delete($id);
        // Failed to delete
        if (!$status) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                "error" => "User could not be deleted.",
            ]);
            die();
        }
        // Deleted successfully. Return No Content
        http_response_code(204); // No Content
        die();
    }

    /**
     * Accepts the site agreement for a given user
     */
    public static function acceptAgreement(User $user) {
        header("Content-Type: application/json");
        try {
            $user->acceptAgreement();
            http_response_code(204); // No Content
            die();
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                "error" => $e->getMessage()
            ]);
            die();
        }
    }

    /**
     * Resets the agreement status of all users. Use when the agreement
     * has changed and users must reaccept
     */
    public static function resetAgreements(User $user) {
        header("Content-Type: application/json");
        if (!$user->isAdmin()) {
            http_response_code(403); // Forbidden
            echo json_encode([
                "error" => "Only site admins can reset the agreement"
            ]);
            die();
        }

        try {
            User::unacceptAgreementForAll();
            http_response_code(204); // No Content
            die();
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                "error" => $e->getMessage()
            ]);
            die();
        }
    }

    public static function getUsersByTimestamp(User $user) { //Function to get user info after a certain timestamp
        header("Content-Type: application/json");
        if (!$user->isAdmin()) {
            http_response_code(403); // Forbidden
            echo json_encode([
                "error" => "Only site admins can reset the agreement"
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
            $users = User::loadByAddedDate($starting_date, $direction);
            http_response_code(200); // OK
            echo json_encode([
                "users" => $users
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