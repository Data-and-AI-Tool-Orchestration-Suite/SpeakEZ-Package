<?php

    define('CONFIG_FILE', __DIR__ . '/config.php');
    define('MODELS_DIR', __DIR__ . '/models/');
    define('UTILITIES_DIR', __DIR__ . '/utilities/');
    define('VIEWS_DIR', __DIR__ . '/views/');
    define('BANNER_VIEWS_DIR', __DIR__ . '/views/banner/');


    /**
     * Define globally for all imported controllers
     */
    $CONFIG = include(CONFIG_FILE);
    $rootURL = $CONFIG['rootURL'];

    /**
     * Retrieves the data of a POST request from either the POST body
     * or the input file contents if data was passed by JSON. This 
     * function should be accessible to the entire codebase.
     * @return array
     */
    function getPostData(): array {
        // Try to get standard POST data
        if (!empty($_POST)) {
            return $_POST;
        }
    
        // Try to get JSON data from the request body
        $jsonInput = file_get_contents("php://input");
        $data = json_decode($jsonInput, true);
    
        // Ensure it's an array, return empty array if decoding fails
        return is_array($data) ? $data : [];
    }

    /**
     * Require utilities
     *
     * Importing db.php here creates a new PostgresDB session. Set
     * requirments for each session after page load, generally in 
     * the get_session() function.
     * (i.e.: Row Level Security, session tenant_id, etc.)
     */ 
    require_once __DIR__ . '/utilities/db.php';
    require_once __DIR__ . '/utilities/UUID.php';
    
    /**
     * Load S3 Plugin before other classes to make it available where necessary
     */
    include_once __DIR__ . '/controllers/PluginsController.php';
    if (Plugin::isPluginActiveByName("S3")){
        include_once __DIR__ . '/utilities/S3Utility.php';
    }
    if (Plugin::isPluginActiveByName("LLM")){
        include_once __DIR__ . '/utilities/LLMUtility.php';
    }

    include_once __DIR__ . '/controllers/RootController.php';
    include_once __DIR__ . '/controllers/UsersController.php';
    include_once __DIR__ . '/controllers/ProcessController.php';
    include_once __DIR__ . '/controllers/FileController.php';
    include_once __DIR__ . '/controllers/JobController.php';
    include_once __DIR__ . '/controllers/ProjectsController.php';
    include_once __DIR__ . '/controllers/TenantsController.php';
    include_once __DIR__ . '/controllers/MetricsController.php';
    include_once __DIR__ . '/controllers/LLMController.php';
    include_once __DIR__ . '/controllers/BannerController.php';

    

    /**
     * Load Other Plugins
     */
    if (Plugin::isPluginActiveByName("api_keys")){
        include_once __DIR__ . '/controllers/APIKeyController.php';
    }
    if (Plugin::isPluginActiveByName("projects")){
        include_once __DIR__ . '/controllers/ProjectsController.php';
    }
    if (Plugin::isPluginActiveByName("site_banner")){
        include_once __DIR__ . '/controllers/SiteBannerController.php';
    }

    // Session Auth Helper Functions
    include_once __DIR__ . '/session_auth.php';

    
    

    /* Initializing router and routes below */
    $router = new AltoRouter();

    /* RootController routes */
    try {
        $router->map('GET', $rootURL.'/', function() {
            RootController::index(get_session());
        }, 'dashboard');
        $router->map('GET', $rootURL.'/login', function() {
            RootController::login();
        }, 'login');
        $router->map('GET', $rootURL.'/callback', function() {
            RootController::callback();
        }, 'callback');
        $router->map('GET', $rootURL.'/logout', function() {
            RootController::logout();
        }, 'logout');
        $router->map('GET', $rootURL.'/no-access', function() {
            RootController::unauthorized(get_session());
        }, 'no-access');
        $router->map('GET', $rootURL.'/unauthorized', function() {
            // RootController::unauthorized(get_session()); // EASTER EGG
            RootController::unauthorized(get_session());
        }, 'unauthorized');
        $router->map('GET', $rootURL.'/user-guide', function() use ($config) {
            RootController::userGuide(get_session());
        }, 'user-guide');
        $router->map('GET', $rootURL.'/health', function() {
            RootController::healthCheck();
        }, 'health-check');
    } catch (Exception $e) {
        die("Failed to create route(s) from RootController section: " . $e->getMessage());
    }

    /* ProcessController routes */
    try {
        $router->map('POST', $rootURL.'/updates/[*:job_type]?', function($job_type=null) {
            ProcessController::updateJob();
        }, 'processing-update-status');
        $router->map('GET', $rootURL.'/processing/check-status', function() {
            ProcessController::getStatus(get_session());
        }, 'processing-get-status');
    } catch (Exception $e) {
        die("Failed to create route(s) from ProcessController section: " . $e->getMessage());
    }

    /* FileController routes */
    try {
        $router->map('GET', $rootURL.'/files/list', function() {
            FileController::listForDatatable(get_session());
        }, 'files-list');
        $router->map('POST', $rootURL . '/files/get-jobs', function() {
            FileController::get_jobs(get_session(["User", "Admin"]));
        }, 'files-get-jobs');
        $router->map('POST', $rootURL.'/files/upload', function() {
            FileController::saveFiles(get_session());
        }, 'files-upload');
        $router->map('POST', $rootURL.'/files/upload-synchronifier', function() {
            FileController::saveSynchronifierFiles(get_session());
        }, 'files-upload-synchronifier');
        $router->map('GET', $rootURL.'/files/collection-codes', function() {
            FileController::getCollectionCodes(get_session());
        }, 'files-collection-codes');
        $router->map('POST', $rootURL.'/files/archive', function() {
            FileController::archiveFiles(get_session());
        }, 'files-archive');
        $router->map('GET', $rootURL.'/files/download-id', function() {
            FileController::downloadFiles(get_session());
        }, 'files-download-id');
        $router->map('GET', $rootURL.'/files/download-cc', function() {
            FileController::downloadFilesByCollectionCode(get_session());
        }, 'files-download-cc');
        $router->map('POST', $rootURL.'/files/update', function() {
            FileController::updateStatus(get_session());
        }, 'files-update-status');
        $router->map('POST', $rootURL.'/files/delete', function() {
            FileController::deleteFile(get_session());
        }, 'files-delete');
        $router->map('POST', $rootURL.'/files/create-clearml-dataset', function() {
            FileController::createClearMLDataset(get_session());
        }, 'files-create-clearml-dataset');
        $router->map('POST', $rootURL.'/files/create-clearml-dataset-synchronifier', function() {
            FileController::createClearMLDatasetSynchronifier(get_session());
        }, 'files-create-clearml-dataset-synchronifier');
        $router->map('GET', $rootURL.'/files/download_zip', function() {
            FileController::downloadZipFile(get_session());
        }, 'files-download-zip');
        $router->map('GET', $rootURL.'/files/content', function() {
            FileController::getFileContent(get_session());
        }, 'files-get-content');
        $router->map('POST', $rootURL.'/files/save-content', function() {
            FileController::saveFileContent(get_session());
        }, 'files-save-content');
        $router->map('GET', $rootURL . '/files/analyze/[*:id]/[*:collection_code]/[*:file]', function($id, $collection_code, $file) use ($config) {
            FileController::analyze_chat_file(get_session(["User", "Admin"]), $id, $collection_code, $file);
        }, 'files-analyze');
        // $router->map('GET', $rootURL . '/files/analyze-chat/[*:id]/[*:collection_code]/[*:file]', function($id, $collection_code, $file) use ($config) {
        //     FileController::analyze_chat_file(get_session(["User", "Admin"]), $id, $collection_code, $file);
        // }, 'files-analyze-chat');
        $router->map('GET', $rootURL . '/files/touchup/[*:id]/[*:collection_code]/[*:file]', function($id, $collection_code, $file) use ($config) {
            FileController::touchup_file(get_session(["User", "Admin"]), $id, $collection_code, $file);
        }, 'files-touchup');
        $router->map('POST', $rootURL.'/files/upload-transcript', function() {
            FileController::uploadTranscript(get_session(["User", "Admin"]));
        }, 'files-upload-transcript');
        $router->map('POST', $rootURL.'/files/create-clearml-dataset-transcript', function() {
            FileController::createClearMLDatasetTranscript(get_session());
        }, 'files-create-clearml-dataset-transcript');
        $router->map('POST', $rootURL.'/files/calculate-duration', function() {
            FileController::calculateAndStoreMediaDuration(get_session());
        }, 'files-calculate-media-duration');
    } catch (Exception $e) {
        die("Failed to create route(s) from FileController section: " . $e->getMessage());
    }

    /* JobController routes */
    try {
        $router->map('POST', $rootURL.'/job/start', function() {
            JobController::startJob(get_session());
        }, 'job-start');
        $router->map('POST', $rootURL.'/job/start-custom', function() {
            JobController::startCustomJob(get_session());
        }, 'job-start-custom');
        $router->map('POST', $rootURL.'/job/start-all', function() {
            JobController::startAllJobs(get_session());
        }, 'job-start-all');
        $router->map('GET', $rootURL.'/jobs/get-fields', function() {
            JobController::getJobFields(get_session());
        }, 'job-get-fields');
        $router->map('POST', $rootURL.'/jobs/cancel', function() {
            JobController::cancelJob(get_session());
        }, 'job-cancel');
        $router->map('POST', $rootURL.'/jobs/cancel-celery', function() {
            JobController::cancelCeleryTask(get_session());
        }, 'job-cancel-celery');
    } catch (Exception $e) {
        die("Failed to create route(s) from JobController section: " . $e->getMessage());
    }

    /* Sam routes */
    try {
        $router->map('GET', $rootURL.'/sam/is/cool', function() {
            echo "Sam is poop :)";
        }, 'sam-is-cool');
    } catch (Exception $e) {
        die("Failed to create route(s) from JobController section: " . $e->getMessage());
    }


    try {
        /* UsersController routes */
        $router->map('GET', $rootURL.'/users', function() {
            UsersController::index(get_session(["Admin"]));
        }, 'users-index');
        $router->map('GET', $rootURL.'/users/list', function() {
            UsersController::listForDatatable(get_session(["Admin"]));
        }, 'users-for-datatable');
        $router->map('POST', $rootURL.'/users/submit', function() {
            UsersController::submit(get_session(["Admin"]));
        }, 'users-submit');
        $router->map('GET', $rootURL.'/users/get-roles', function() {
            UsersController::getRoles(get_session(["Admin"]));
        }, 'users-get-roles');
        $router->map('POST', $rootURL.'/users/delete', function() {
            UsersController::deleteUser(get_session(["Admin"]));
        }, 'users-delete');
        $router->map('GET', $rootURL.'/users/get-by-timestamp', function() {
            UsersController::getUsersByTimestamp(get_session(["Admin"], true));
        }, 'users-get-by-timestamp');
        if (Plugin::isPluginActiveByName("user_agreement")){
            $router->map('GET', $rootURL.'/users/accept-agreement', function() {
                UsersController::acceptAgreement(get_session());
            }, 'users-accept-agreement');
            $router->map('GET', $rootURL.'/users/reset-agreements', function() {
                UsersController::resetAgreements(get_session(["Admin"]));
            }, 'users-reset-agreement');
        }
        
    } catch (Exception $e) {
        die("Failed to create route(s) from UsersController section: " . $e->getMessage());
    }

    try {
        /* TenantsController routes */
        $router->map('GET', $rootURL.'/tenants', function() {
            TenantsController::index(get_session(["Admin"]));
        }, 'tenants-index');
        $router->map('GET', $rootURL.'/tenants/list', function() {
            TenantsController::listForDatatable(get_session(["Admin"]));
        }, 'tenants-for-datatable');
        $router->map('GET', $rootURL.'/tenants/get-tenants', function() {
            TenantsController::getTenants(get_session(["Admin"]));
        }, 'tenants-get-tenants');
        $router->map('POST', $rootURL.'/tenants/submit', function() {
            TenantsController::submit(get_session(["Admin"]));
        }, 'tenants-submit');
        $router->map('GET', $rootURL.'/tenants/list-by-user', function() {
            TenantsController::listByUser(get_session(["User", "Admin"]));
        }, 'tenants-list-by-user');
        $router->map('GET', $rootURL.'/tenants/[:tenantId]/get-roles', function($tenantId) {
            TenantsController::getRoles(get_session(["User", "Admin"]), $tenantId);
        }, 'tenants-get-roles-for-tenant');
        $router->map('POST', $rootURL.'/tenants/delete', function() {
            TenantsController::delete(get_session(["Admin"]));
        }, 'tenants-delete');
        $router->map('GET', $rootURL.'/tenants/[:tenantId]/manage', function($tenantId) {
            TenantsController::manage(get_session(["User", "Admin"]), $tenantId);
        }, 'tenants-manage');
        $router->map('GET', $rootURL.'/tenants/[:tenantId]/list-users', function($tenantId) {
            TenantsController::listUsers(get_session(["User", "Admin"]), $tenantId);
        }, 'tenants-list-users');
        $router->map('POST', $rootURL.'/tenants/[:tenantId]/submit-user', function($tenantId) {
            TenantsController::submitUser(get_session(["User", "Admin"]), $tenantId);
        }, 'tenants-submit-user');
        $router->map('POST', $rootURL.'/tenants/[:tenantId]/remove-user', function($tenantId) {
            TenantsController::removeUser(get_session(["User", "Admin"]), $tenantId);
        }, 'tenants-remove-user');
        $router->map('POST', $rootURL.'/tenants/switch-tenant', function() {
            TenantsController::switchTenant(get_session(["User", "Admin"]));
        }, 'tenants-switch-tenant');
        $router->map('POST', $rootURL.'/tenants/[:tenantId]/save-styles', function($tenantId) {
            TenantsController::saveStyles(get_session(["User", "Admin"]), $tenantId);
        }, 'tenants-save-styles');
        $router->map('GET', $rootURL.'/tenants/[:tenantId]/metrics', function($tenantId) {
            TenantsController::metrics(get_session(["User", "Admin"]), $tenantId);
        }, 'tenants-metrics');
    } catch (Exception $e) {
        die("Failed to create route(s) from TenantsController section: " . $e->getMessage());
    }

    try {
        /* PluginsController routes */
        $router->map('GET', $rootURL.'/plugins', function() {
            PluginsController::index(get_session(["Admin"]));
        }, 'plugins-index');
        $router->map('GET', $rootURL.'/plugins/list', function() {
            PluginsController::listForDatatable(get_session(["Admin"]));
        }, 'plugins-for-datatable');
        $router->map('GET', $rootURL.'/plugins/activate/[*:id]', function($id) {
            PluginsController::updateActivation(get_session(["Admin"]), $id, true);
        }, 'plugins-activate');
        $router->map('GET', $rootURL.'/plugins/deactivate/[*:id]', function($id) {
            PluginsController::updateActivation(get_session(["Admin"]), $id, false);
        }, 'plugins-deactivate');
        // Load API Key routes if plugin is active 
        if (Plugin::isPluginActiveByName("api_keys")){
            /* APIKeyController routes */
            try {
                $router->map('GET', $rootURL . '/api-keys', function() use ($config) {
                    APIKeyController::index(get_session(["User", "Admin"]));
                }, 'api-keys-index');
                $router->map('GET', $rootURL . '/api-keys/list', function() use ($config) {
                    APIKeyController::listForDatatable(get_session(["User", "Admin"]));
                }, 'api-keys-list');
                $router->map('POST', $rootURL . '/api-keys/save', function() use ($config) {
                    APIKeyController::save(get_session(["User", "Admin"]));
                }, 'api-keys-save');
                $router->map('POST', $rootURL . '/api-keys/delete', function() use ($config) {
                    APIKeyController::delete(get_session(["User", "Admin"]));
                }, 'api-keys-delete');
            } catch (Exception $e) {
                die("Failed to create route(s) from APIKeyController section: " . $e->getMessage());
            }
        }
        if (Plugin::isPluginActiveByName("projects")) {
            /* ProjectsController routes */
            try {
                $router->map('GET', $rootURL.'/projects', function() {
                    ProjectsController::index(get_session(["User", "Admin"]));
                }, 'projects-index');
                $router->map('GET', $rootURL.'/projects/list', function() {
                    ProjectsController::listForDatatable(get_session(["User", "Admin"]));
                }, 'projects-list');
                $router->map('GET', $rootURL.'/projects/[*:id]/list-members', function($id) {
                    ProjectsController::listMembers(get_session(["User", "Admin"]), $id, false);
                }, 'projects-list-members');
                $router->map('GET', $rootURL.'/projects/[:id]/list-unaffiliated-users', function($id) {
                    ProjectsController::listMembers(get_session(["User", "Admin"]), $id, true);
                }, 'projects-list-unaffililated-users');
                $router->map('POST', $rootURL.'/projects/[:id]/add-member', function($id) {
                    ProjectsController::addMember(get_session(["User", "Admin"]), $id);
                }, 'projects-add-member');
                $router->map('POST', $rootURL.'/projects/[:id]/remove-member', function($id) {
                    ProjectsController::removeMember(get_session(["User", "Admin"]), $id);
                }, 'projects-remove-member');
                $router->map('POST', $rootURL.'/projects/save', function() {
                    ProjectsController::save(get_session(["User", "Admin"]));
                }, 'projects-save');
                $router->map('POST', $rootURL.'/projects/delete', function() {
                    ProjectsController::delete(get_session(["User", "Admin"]));
                }, 'projects-delete');
                $router->map('GET', $rootURL.'/projects/[:id]/details', function($id) {
                    ProjectsController::details(get_session(["User", "Admin"]), $id);
                }, 'projects-details');
                $router->map('GET', $rootURL . '/projects/jobs/[*:id]', function($id) use ($config) {
                    ProjectsController::project_jobs_index(get_session(["User", "Admin"]), $id);
                }, 'projects-jobs');
                $router->map('GET', $rootURL . '/projects/dashboard/[*:id]', function($id) use ($config) {
                    ProjectsController::dashboard(get_session(["User", "Admin"]), $id);
                }, 'projects-dashboard');
                $router->map('GET', $rootURL . '/projects/get-running-jobs/[*:id]', function($id) use ($config) {
                    ProjectsController::getRunningJobs(get_session(["User", "Admin"]), $id);
                }, 'projects-get-running-jobs');
                $router->map('GET', $rootURL . '/projects/[*:id]/collections/[*:cc]', function($id, $cc) use ($config) {
                    ProjectsController::ccDashboard(get_session(["User", "Admin"]), $id, $cc);
                }, 'projects-collections-dashboard');
                

        
                if (Plugin::isPluginActiveByName("api_keys")) {
                    $router->map('GET', $rootURL . '/projects/[:id]/list-api-keys', function($id) use ($config) {
                        ProjectsController::listRelation(
                            get_session(["User", "Admin"]), 
                            $id,                // Project ID
                            "project_api_keys", // Link table name
                            "api_key",          // Link table field that matches the related table's reference field
                            ["name", "id"],     // List of fields in the related table to include in the filter
                            "api_keys",         // Related table name
                            "id"                // Related table reference field
                        );
                    }, 'projects-list-api-keys');

                    $router->map('GET', $rootURL . '/api/list-projects', function() {
                        ProjectsController::ApiListProjects(get_session(["User", "Admin"], true));
                    }, 'api-list-projects');
                    $router->map('GET', $rootURL . '/api/list-collections', function() {
                        ProjectsController::ApiListProjectCollections(get_session(["User", "Admin"], true));
                    }, 'api-list-project-collections');
                    $router->map('GET', $rootURL . '/api/list-files', function() {
                        ProjectsController::ApiListFiles(get_session(["User", "Admin"], true));
                    }, 'api-list-files');
                    $router->map('GET', $rootURL . '/api/get-transcript', function() {
                        FileController::ApiGetTranscript(get_session(["User", "Admin"], true));
                    }, 'api-get-transcripts');
                }
                $router->map('GET', $rootURL . '/projects/list-collections/[*:id]', function($id) use ($config) {
                    ProjectsController::listCollections(get_session(["User", "Admin"]), $id);
                }, 'projects-list-collections');
                
                /**
                 * Example implementation of adding a related element.
                 * Some handling must be done in routes.php because of the need to load the element and manage ownership.
                 * Specify handling of this object before calling the controller function.
                 */
                // $router->map('POST', $rootURL.'/projects/[:id]/add-collection', function($id) {
                //     $user = get_session(["User", "Admin"]);
                //     $rawJsonData = file_get_contents("php://input");
                //     $postData = json_decode($rawJsonData, true);
                //     if (empty($postData)){
                //         $postData = $_POST;
                //     }
                //     if (!isset($postData["collection_id"])) {
                //         http_response_code(400); // Bad Request
                //         echo json_encode([
                //             "error" => "You must include the ID of the related element in your request",
                //         ]);
                //         die();
                //     }
                //     $collectionId = $postData["collection_id"];
                //     $collection = Collection::withId($collectionId);
                //     if (is_null($collection) || $user->getId() != $collection->getOwnerId()) {
                //         http_response_code(403); // Forbidden
                //         echo json_encode([
                //             "error" => "You do not own a collection with specified ID",
                //         ]);
                //         die();
                //     }
                //     ProjectsController::addRelation(
                //         $user,                 // The user   
                //         $projectId,            // The project ID   
                //         $postData,             // The POST data
                //         $collectionId,         // The value to add to the link table for the related field
                //         "project_collections", // The name of the link table
                //         "collection_id"        // The name of the field to edit in the link table
                //     );
                // }, 'projects-add-collection');
                /**
                 * Example implementation of removing a related element.
                 * Some handling must be done in routes.php to make the request field unique to the route.
                 * Specify handling of this object before calling the controller function.
                 */
                // $router->map('POST', $rootURL . '/projects/[:id]/remove-collection', function($id) {
                //     $user = get_session(["User", "Admin"]);
                //     $rawJsonData = file_get_contents("php://input");
                //     $postData = json_decode($rawJsonData, true);
                //     if (empty($postData)){
                //         $postData = $_POST;
                //     }
                //     if (!isset($postData["collection_id"])) {
                //         http_response_code(400); // Bad Request
                //         echo json_encode([
                //             "error" => "You must include the collection ID in your request",
                //         ]);
                //         die();
                //     }
                //     $collectionId = $postData["collection_id"];
                //     ProjectsController::removeRelation(
                //         $user,                 // The user
                //         $projectId,            // The project ID
                //         $postData,             // The POST data
                //         $collectionId,         // The value to add to the link table for the related field
                //         "project_collections", // The name of the link table
                //         "collection_id"        // The name of the field to edit in the link table
                //     );
                // }, 'projects-remove-collection');



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
                }, 'projects-remove-api-key');


            } catch (Exception $e) {
                die("Failed to create route(s) from ProjectsController section: " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        die("Failed to create route(s) from PluginsController section: " . $e->getMessage());
    }

    /* Metrics Controller routes */
    try {
        $router->map('GET', $rootURL.'/metrics', function() {
            MetricsController::index(get_session(["Admin"]));
        }, 'metrics-index');
        $router->map('GET', $rootURL.'/metrics/list-usage', function() {
            MetricsController::listUsageForDatatable(get_session(["Admin"]));
        }, 'metrics-list-usage');
        $router->map('GET', $rootURL.'/metrics/list-usage-breakdown', function() {
            MetricsController::listUsageBreakdown(get_session(["Admin"]));
        }, 'metrics-list-usage-breakdown');
        $router->map('POST', $rootURL.'/metrics/update', function() {
            MetricsController::update();
        }, 'metrics-update');
        $router->map('GET', $rootURL.'/metrics/[:tenantId]/list-usage-breakdown', function($tenantId) {
            MetricsController::listUsageBreakdownForTenant(get_session(["User", "Admin"]), $tenantId);
        }, 'metrics-list-usage-breakdown-for-tenant');
        $router->map('GET', $rootURL.'/metrics/[:tenantId]/list-duration-rows', function($tenantId) {
            MetricsController::listDurationForTenant(get_session(["User", "Admin"]), $tenantId);
        }, 'metrics-list-duration-rows-for-tenant');
        $router->map('GET', $rootURL.'/metrics/[:tenantId]/total-num-transcripts', function($tenantId) {
            MetricsController::getTotalNumTranscriptsByTenant(get_session(["User", "Admin"]), $tenantId);
        }, 'metrics-total-num-transcripts-for-tenant');
        $router->map('GET', $rootURL.'/metrics/get_recent_metrics', function() {
            MetricsController::getRecentMetrics(get_session(["Admin"], true));
        }, 'metrics-get-recent-metrics');
    } catch (Exception $e) {
        die("Failed to create route(s) from MetricsController section: " . $e->getMessage());
    }

    
    try {
        $router->map('POST', $rootURL.'/llm/stream', function() {
            error_log("LLM Stream Request");
            LLMController::streamLLMRequest(get_session(["User", "Admin"]));
        }, 'llm-stream');
        $router->map('GET', $rootURL.'/llm/get-convo', function() {
            LLMController::loadConversation(get_session(["User", "Admin"]));
        }, 'llm-load-conversation');
        $router->map('GET', $rootURL.'/llm/list-conversations', function() {
            LLMController::listConversations(get_session(["User", "Admin"]));
        }, 'llm-list-conversations');
    } catch (Exception $e) {
        die("Failed to create route(s) from LLMController section: " . $e->getMessage());
    }
    if (plugin::isPluginActiveByName("site_banner")) {
        try {
            $router->map('GET', $rootURL.'/banner-settings', function() {
                SiteBannerController::index(get_session(["Admin"]));
            }, 'banner-settings');
            $router->map('POST', $rootURL.'/set-banner', function() {
                SiteBannerController::setBanner(get_session(["Admin"]));
            }, 'banner-settings-set');
        } catch (Exception $e) {
            die("Failed to create route(s) from SiteBannerController section: " . $e->getMessage());
        }
    }


    // Add a catch-all proxy for /api/* routes not already defined
    $definedApiRoutes = [
        '/api/list-projects',
        '/api/list-collections',
        '/api/list-files',
        '/api/get-transcript',
        // Add any other explicitly defined /api routes here
    ];

    $requestUri = $_SERVER['REQUEST_URI'];
    $parsedUrl = parse_url($requestUri);
    $path = $parsedUrl['path'] ?? '';


        $isApi = preg_match('#^' . preg_quote($rootURL, '#') . '/api/#', $path);
        $isDefinedApi = in_array(str_replace($rootURL, '', $path), $definedApiRoutes);

        if ($isApi && !$isDefinedApi) {
            // Proxy to backend, removing /api from the path
            $backendBase = $CONFIG["backend_server"];
            $apiPrefix = $rootURL . '/api';
            if (strpos($path, $apiPrefix) === 0) {
                $proxyPath = substr($path, strlen($apiPrefix));
                if ($proxyPath === false) $proxyPath = '';
            } else {
                $proxyPath = $path;
            }
            if ($proxyPath === '' || $proxyPath[0] !== '/') {
                $proxyPath = '/' . ltrim($proxyPath, '/');
            }
            $proxyUrl = rtrim($backendBase, '/') . $proxyPath;

            $method = $_SERVER['REQUEST_METHOD'];
            $headers = [];
            foreach (getallheaders() as $key => $value) {
                if (strtolower($key) !== 'host') {
                    $headers[] = "$key: $value";
                }
            }
            $body = file_get_contents('php://input');

            $ch = curl_init($proxyUrl . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            curl_setopt($ch, CURLOPT_HEADER, false);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if ($contentType) {
                header('Content-Type: ' . $contentType);
            }
            http_response_code($httpCode);
            echo $response;
            exit;
        }

    $match = $router->match();

    // Call closure or throw 404 status
    if ($match && is_callable($match['target'])) {
        call_user_func_array($match['target'], $match['params']);
    } else {
        // No route was matched
        $from = $_SERVER['REQUEST_URI'];
        error_log("ROUTE NOT FOUND: " . $from);

        if (isJsonRequest()){
            http_response_code(404);
            header('Content-Type: application/json');
            $response = [
                'success' => false,
                'error' => 'Not Found',
                'error_message' => 'The requested resource was not found on this server.'
            ];
            echo json_encode($response);
        } else {
            if (strlen($from) > 20)
                $from = substr($from, 0, 20) . '...';
            $_SESSION['FLASH_ERROR'] = "No page exists at $from";
            if(isset($_SERVER['HTTP_REFERER'])) {
                header('Location: ' . $_SERVER['HTTP_REFERER']);
            } else {
                header('Location: ' . $rootURL . '/');
            }
        }
    }

