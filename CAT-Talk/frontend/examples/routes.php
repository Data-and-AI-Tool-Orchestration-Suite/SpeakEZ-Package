<?php

/**
 * Example implementation of adding a related element.
 * Some handling must be done in routes.php because of the need to load the element and manage ownership.
 * Specify handling of this object before calling the controller function.
 */
$router->map('POST', $rootURL.'/projects/[:id]/add-collection', function($id) {
    $user = get_session(["User", "Admin"]);
    $rawJsonData = file_get_contents("php://input");
    $postData = json_decode($rawJsonData, true);
    if (empty($postData)){
        $postData = $_POST;
    }
    if (!isset($postData["collection_id"])) {
        http_response_code(400); // Bad Request
        echo json_encode([
            "error" => "You must include the ID of the related element in your request",
        ]);
        die();
    }
    $collectionId = $postData["collection_id"];
    $collection = Collection::withId($collectionId);
    if (is_null($collection) || $user->getId() != $collection->getOwnerId()) {
        http_response_code(403); // Forbidden
        echo json_encode([
            "error" => "You do not own a collection with specified ID",
        ]);
        die();
    }
    ProjectsController::addRelation(
        $user,                 // The user   
        $projectId,            // The project ID   
        $postData,             // The POST data
        $collectionId,         // The value to add to the link table for the related field
        "project_collections", // The name of the link table
        "collection_id"        // The name of the field to edit in the link table
    );
}, 'projects-add-collection');
/**
 * Example implementation of removing a related element.
 * Some handling must be done in routes.php to make the request field unique to the route.
 * Specify handling of this object before calling the controller function.
 */
$router->map('POST', $rootURL . '/projects/[:id]/remove-collection', function($id) {
    $user = get_session(["User", "Admin"]);
    $rawJsonData = file_get_contents("php://input");
    $postData = json_decode($rawJsonData, true);
    if (empty($postData)){
        $postData = $_POST;
    }
    if (!isset($postData["collection_id"])) {
        http_response_code(400); // Bad Request
        echo json_encode([
            "error" => "You must include the collection ID in your request",
        ]);
        die();
    }
    $collectionId = $postData["collection_id"];
    ProjectsController::removeRelation(
        $user,                 // The user
        $projectId,            // The project ID
        $postData,             // The POST data
        $collectionId,         // The value to add to the link table for the related field
        "project_collections", // The name of the link table
        "collection_id"        // The name of the field to edit in the link table
    );
}, 'projects-remove-collection');

if (Plugin::isPluginActiveByName("api_keys")) {
    /**
     * Example implementation of adding a related element.
     * Some handling must be done in routes.php because of the need to load the element and manage ownership.
     * Specify handling of this object before calling the controller function.
     */
    $router->map('POST', $rootURL.'/projects/[:id]/add-api-key', function($id) {
        $user = get_session(["User", "Admin"], true);
        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true);
        if (empty($postData)){
            $postData = $_POST;
        }
        if (!isset($postData["api_key"])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "You must include the API Key in your request",
            ]);
            die();
        }
        $apiKeyId = $postData["api_key"];
        $apiKey = APIKey::withId($apiKeyId);
        if (is_null($apiKey) || $user->getId() != $apiKey->getOwnerId()) {
            http_response_code(403); // Forbidden
            echo json_encode([
                "error" => "You do not own an API Key with specified ID",
            ]);
            die();
        }
        ProjectsController::addRelation(
            $user,                 // The user  
            $projectId,            // The project ID    
            $postData,             // The POST data
            $apiKeyId,             // The value to add to the link table for the related field
            "project_api_keys",    // The name of the link table
            "api_key"              // The name of the field to edit in the link table
        );
        }, 'projects-add-api-key');
        /**
         * Example implementation of removing a related element.
         * Some handling must be done in routes.php to make the request field unique to the route.
         * Specify handling of this object before calling the controller function.
         */
        $router->map('POST', $rootURL . '/projects/[:id]/remove-api-key', function($id) {
        $user = get_session(["User", "Admin"], true);
        $rawJsonData = file_get_contents("php://input");
        $postData = json_decode($rawJsonData, true);
        if (empty($postData)){
            $postData = $_POST;
        }
        if (!isset($postData["api_key"])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                "error" => "You must include the API Key in your request",
            ]);
            die();
        }
        $apiKeyId = $postData["api_key"];
        ProjectsController::removeRelation(
            $user,                 // The user
            $projectId,            // The project ID 
            $postData,             // The POST data
            $apiKeyId,             // The value to add to the link table for the related field
            "project_api_keys",    // The name of the link table
            "api_key"              // The name of the field to edit in the link table
        );
    },
    'projects-remove-api-key');
}