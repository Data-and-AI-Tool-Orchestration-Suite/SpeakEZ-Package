<?php

require_once __DIR__ . '/../models/Resource.php';

abstract class ResourcesController {
    protected const RESOURCE_CLASS = Resource::class;  // The model class being controlled
    protected const RESOURCE_TYPE = "Resource";        // The type for messages. First letter should be capitalized.  

    public function __construct() {
        if (!isset($this->RESOURCE_CLASS)) {
            throw new Exception("Controller must define RESOURCE_CLASS");
        }
    }

    // submit function must be implemented at the child level
    abstract public static function submit(User $user): void;
    

    /**
     * Delete the specified resource
     * Requires manage permissions
     */
    public static function delete(User $user, string $resourceId) {
        header("Content-Type: application/json");
        $postData = getPostData();

        // Check resource exists
        $resource = static::RESOURCE_CLASS::withId($resourceId);
        if (!$resource) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => static::RESOURCE_TYPE . " with specified ID does not exist"
            ]);
            die();
        }

        // Check user has manage rights
        $userId = $user->getId();
        if (!$resource->canManage($userId)) {
            http_response_code(403); // Forbidden
            echo json_encode([
                'error' => "You do not have rights to delete this " . strtolower(static::RESOURCE_TYPE)
            ]);
            die();
        }

        try {
            static::RESOURCE_CLASS::delete($resourceId);
            http_response_code(204); // No Content
            die();
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                'error' => $e->getMessage()
            ]);
            die();
        }
    }

    /**
     * Retrieve the resources for a datatable
     */
    public static function listForDatatable(User $user) {
        header("Content-Type: application/json");
        $userId = $user->getId();

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
        $results = static::RESOURCE_CLASS::listForDatatable($userId, $start, $length, $order_by, $order_dir, $filter);
        foreach ($results as $result) {
            $data_row = $result;
            $data_row['DT_RowId'] = $idx++;
            array_push($data, $data_row);
        }
        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => intval(static::RESOURCE_CLASS::countForDatatable($userId)),
                'recordsFiltered' => intval(static::RESOURCE_CLASS::countFilteredForDatatable($userId, $filter)),
                'data' => $data,
            )
        );
    }


    /**
     * Retrieve the list of users with access to the resource for a datatable
     */
    public static function listUsersWithAccessForDatatable(User $user, string $resourceId) {
        header("Content-Type: application/json");
        $userId = $user->getId();

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

        // Verify resource exists
        $resource = static::RESOURCE_CLASS::withId($resourceId);
        if (!$resource) {
            http_response_code(400); // Bad Request
            echo json_encode(
                array(
                    'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                    'recordsTotal' => 0,
                    'recordsFiltered' => 0,
                    'data' => $data,
                    'error' => static::RESOURCE_TYPE . " with the provided ID does not exist"
                )
            );
            die();
        }

        // Verify user can read resource
        if (!$resource->canRead($userId)) {
            http_response_code(403); // Forbidden
            echo json_encode(
                array(
                    'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                    'recordsTotal' => 0,
                    'recordsFiltered' => 0,
                    'data' => $data,
                    'error' => "You do not have access to this " . strtolower(static::RESOURCE_TYPE)
                )
            );
            die();
        }

        $results = static::RESOURCE_CLASS::listUsersWithAccessForDatatable($resourceId, $start, $length, $order_by, $order_dir, $filter);
        foreach ($results as $result) {
            $data_row = $result;
            $data_row['DT_RowId'] = $idx++;
            array_push($data, $data_row);
        }
        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => intval(static::RESOURCE_CLASS::countUsersWithAccessForDatatable($resourceId)),
                'recordsFiltered' => intval(static::RESOURCE_CLASS::countUsersWithAccessFilteredForDatatable($resourceId, $filter)),
                'data' => $data,
            )
        );
    }

    /**
     * Retrieve the list of users without access to the resource for a datatable
     */
    public static function listUsersWithoutAccessForDatatable(User $user, string $resourceId) {
        header("Content-Type: application/json");
        $userId = $user->getId();

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

        // Verify resource exists
        $resource = static::RESOURCE_CLASS::withId($resourceId);
        if (!$resource) {
            http_response_code(400); // Bad Request
            echo json_encode(
                array(
                    'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                    'recordsTotal' => 0,
                    'recordsFiltered' => 0,
                    'data' => $data,
                    'error' => static::RESOURCE_TYPE . " with the provided ID does not exist"
                )
            );
            die();
        }

        // Verify user can read resource
        if (!$resource->canRead($userId)) {
            http_response_code(403); // Forbidden
            echo json_encode(
                array(
                    'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                    'recordsTotal' => 0,
                    'recordsFiltered' => 0,
                    'data' => $data,
                    'error' => "You do not have access to this " . strtolower(static::RESOURCE_TYPE)
                )
            );
            die();
        }

        $results = static::RESOURCE_CLASS::listUsersWithoutAccessForDatatable($resourceId, $start, $length, $order_by, $order_dir, $filter);
        foreach ($results as $result) {
            $data_row = $result;
            $data_row['DT_RowId'] = $idx++;
            array_push($data, $data_row);
        }
        echo json_encode(
            array(
                'draw' => (isset($_GET['draw'])) ? intval($_GET['draw']) : 0,
                'recordsTotal' => intval(static::RESOURCE_CLASS::countUsersWithoutAccessForDatatable($resourceId)),
                'recordsFiltered' => intval(static::RESOURCE_CLASS::countUsersWithoutAccessFilteredForDatatable($resourceId, $filter)),
                'data' => $data,
            )
        );
    }


    /**
     * Revoke user's access to the specified resource
     * Requires manage permission level
     */
    public static function grantAccess(User $user, string $resourceId) {
        header("Content-Type: application/json");
        $postData = getPostData();

        // Check resource exists
        $resource = static::RESOURCE_CLASS::withId($resourceId);
        if (!$resource) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => static::RESOURCE_TYPE . " with specified ID does not exist"
            ]);
            die();
        }

        // Check user has manage rights
        $userId = $user->getId();
        if (!$resource->canManage($userId)) {
            http_response_code(403); // Forbidden
            echo json_encode([
                'error' => "You do not have rights to grant access to this " . strtolower(static::RESOURCE_TYPE)
            ]);
            die();
        }

        // Ensure correct fields are in POST data
        if (!isset($postData['grantee_id'])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => "You must supply the grantee's user ID"
            ]);
            die();
        } 
        if (!isset($postData['permission'])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => "You must supply a permission"
            ]);
            die();
        } 
        
        // Extract POST data to vars
        $granteeUserId = $postData['grantee_id'];
        $permission = $postData['permission'];

        // Ensure user exists
        $grantee = User::withId($granteeUserId);
        if (!$grantee) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => "A user with the specified ID does not exist"
            ]);
            die();
        }

        // Ensure permission is valid
        $valid_permissions = ["read", "write", "manage"]; 
        if (!in_array($permission, $valid_permissions, true)) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => "The permission argument must be one of " . implode(",", $valid_permissions)
            ]);
            die();
        }

        // Request is valid
        try {
            $resource->grantPermission($granteeUserId, $permission);
            http_response_code(204); // No Content
            die();
        } catch (Exception $e) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                'error' => $e->getMessage()
            ]);
            die();
        }
    }

    /**
     * Revoke user's access to the specified resource
     * Requires manage permission level
     */
    public static function revokeAccess(User $user, string $resourceId) {
        header("Content-Type: application/json");
        $postData = getPostData();

        // Check resource exists
        $resource = static::RESOURCE_CLASS::withId($resourceId);
        if (!$resource) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => static::RESOURCE_TYPE . " with specified ID does not exist"
            ]);
            die();
        }

        // Check user has manage rights
        $userId = $user->getId();
        if (!$resource->canManage($userId)) {
            http_response_code(403); // Forbidden
            echo json_encode([
                'error' => "You do not have rights to revoke access to this " . strtolower(static::RESOURCE_TYPE)
            ]);
            die();
        }

        // Ensure correct fields are in POST data
        if (!isset($postData['revokee_id'])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => "You must supply the revokee's user ID"
            ]);
            die();
        } 
        
        // Extract POST data to vars
        $revokeeUserId = $postData['revokee_id'];

        // Ensure user exists
        $revokee = User::withId($revokeeUserId);
        if (!$revokee) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => "A user with the specified ID does not exist"
            ]);
            die();
        }

        // Request is valid
        try {
            $resource->revokePermission($revokeeUserId);
            http_response_code(204); // No Content
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
