<?php

require_once __DIR__ . '/../models/Document.php';

class DocumentsController extends ResourcesController{
    protected const RESOURCE_CLASS = Document::class; // The model class being controlled
    protected const RESOURCE_TYPE = "Document";       // The type for messages. First letter should be capitalized.


    public static function index(User $user): void {
        require EXAMPLES_VIEWS_DIR . 'documents/index.php';
    }

    public static function details(User $user, string $resourceId): void {
        global $rootURL;
        // Check document exists
        $document = Document::withId($resourceId);
        if (!$document) {
            $_SESSION['FLASH_ERROR'] = "A document with the specified ID does not exist";
            http_response_code(302); // Redirect
            header("Location: $rootURL/documents");
            die();
        }

        // Check user has read rights
        $userId = $user->getId();
        if (!$document->canRead($userId)) {
            $_SESSION['FLASH_ERROR'] = "You do not have access to the specified document";
            http_response_code(302); // Redirect
            header("Location: $rootURL/documents");
            die();
        }
        // Standardize name of var for use in `/frontend/views/resources/*`
        $resource = $document;

        require EXAMPLES_VIEWS_DIR . 'documents/details/details.php';
    }

    public static function submit(User $user): void {
        header("Content-Type: application/json");
        $postData = getPostData();
        
        if (!isset($postData['name'])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => "You must supply a document name"
            ]);
            die();
        } 
        if (!isset($postData['content'])) {
            http_response_code(400); // Bad Request
            echo json_encode([
                'error' => "You must supply document contents"
            ]);
            die();
        } 
        
        $name = $postData['name'];
        $content = $postData['content'];
        $id = $postData['id'] ?? null; // Only set if updating a document
        $userId = $user->getId();
    
        try {
            if ($id){ // Updating 
                $id = $postData['id'];
                $document = Document::withId($id);
                if (!$document) {
                    http_response_code(400); // Bad Request
                    echo json_encode([
                        'error' => "A document with the specified ID does not exist"
                    ]);
                    die();
                }
                if (!$document->canWrite($userId)) {
                    http_response_code(400); // Bad Request
                    echo json_encode([
                        'error' => "You do not have writes to edit the specified document"
                    ]);
                    die();
                }

                // Update the object
                $document->setName($name);
                $document->setContent($content);
                // Commit to DB
                $document = $document->save();

                if ($document) {
                    http_response_code(200); // OK
                }
            } else { // Creating
                $document = Document::create($userId, $name, $content);
                if ($document) {
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
        if (!$document) {
            http_response_code(500); // Internal Server Error
            echo json_encode([
                'error' => "Failed to create document"
            ]);
            die();
        }

        echo json_encode([
            'document' => $document
        ]);
        die();
    
    }

}