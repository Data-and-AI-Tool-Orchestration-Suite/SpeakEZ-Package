<?php
    /**
     * Determines if the current request accepts JSON response
     * @return bool
     */
    function isJsonRequest() {
        if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            return true;
        }
        return false;
    }

    /**
     * Handles JSON and other requests with unauthorized users
     * @return bool
     */
    function unauthorized(?User $user=null, int $responseCode=401, string $error="Unauthorized") {
        global $rootURL;
        if (isJsonRequest()) {
            http_response_code($responseCode);
            header('Content-Type: application/json');
            $response = ['error' => $error];
            echo json_encode($response);
            die();
        } else {
            if (is_null($user)){
                $user = UserSession::createEmptyUserSession()->getUser();
            }
            http_response_code($responseCode);
            RootController::no_access($user);
            die();
        }
    }

    /**
     * Verifies user's authentication with API Key
     * @return User|null
     */
    function authenticateViaApiKey(): ?User {
        // Invalid authorization header format
        if (strpos($_SERVER["HTTP_AUTHORIZATION"], "Bearer ") !== 0) {
            error_log("Invalid authorization header format");
            unauthorized();
            return null;
        }
        $apiKeyVal = substr($_SERVER["HTTP_AUTHORIZATION"], 7);
        // Empty API key
        if (!$apiKeyVal) {
            error_log("Invalid token");
            unauthorized();
            return null;
        }
        PostgresDB::resetTenant();
        $apiKey = APIKey::withId($apiKeyVal);
        // Invalid API key
        if (!$apiKey) {
            error_log("Invalid token");
            unauthorized();
            return null;
        }
        $user = $apiKey->getUser();
        // No user associated with API key
        if (!$user) {
            error_log("Invalid API Key");
            unauthorized($user);
            return null;
        }
        // Set tenant context if available
        $tenantId = $apiKey->getTenantId();
        if ($tenantId) {
            PostgresDB::setTenant($tenantId);
        }
        return $user;
    }

    /**
     * Checks if user has the roles required for a given route
     * @return User|null
     */
    function hasRequiredRoles(User $user, array $requiredRoles): bool {
        if (count($requiredRoles) == 0) {
            return true;
        }
        foreach ($requiredRoles as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Handles where no session is active and roles are required
     * @return User|null
     */
    function handleNoSessionWithRolesRequired(
        bool $allowAPI, 
        bool $apiKeysActive, 
        bool $authorizationHeaderSet, 
        array $requiredRoles, 
        string $login_redirect
    ): ?User {
        global $rootURL;
        // API not allowed for this route or plugin not active
        if (!$allowAPI || !$apiKeysActive || !$authorizationHeaderSet) {
            header('Location: ' . $rootURL . $login_redirect);
            die();
        }

        // Try to authenticate via API key
        $user = authenticateViaApiKey();
        if (!$user) {
            return null; // Authentication functions handle their own error responses
        }

        // Check role permissions
        if (!hasRequiredRoles($user, $requiredRoles)) {
            error_log("User lacks role permissions");
            unauthorized($user);
            return null;
        }

        return $user;
    }

    /**
     * Handles where no session is active and roles are not required
     * @return User|null
     */
    function handleNoSessionNoRolesRequired(
        bool $allowAPI, 
        bool $apiKeysActive, 
        bool $authorizationHeaderSet
    ): ?User {
        // Try API key authentication first if available
        if ($allowAPI && $apiKeysActive && $authorizationHeaderSet) {
            $user = authenticateViaApiKey();
            if ($user) {
                return $user;
            }
            // If API key auth fails, fall back to empty session
        }

        // Create empty user session as fallback
        $newSession = UserSession::createEmptyUserSession();
        return $newSession->getUser();
    }

    /**
     * Handles where session exists
     * @return User|null
     */
    function handleExistingSession(
        array $requiredRoles, 
        string $login_redirect
    ): ?User {
        global $rootURL;
        $session_id = $_SESSION['caai_session_id'];
        $session = UserSession::withSessionID($session_id);

        // Invalid session
        if ($session === null) {
            unset($_SESSION['caai_session_id']);
            header('Location: ' . $rootURL . $login_redirect);
            die();
        }

        // Check session expiration
        $expiration_time = new DateTime($session->getExpires(), new DateTimeZone('UTC'));
        $current_time = new DateTime('now', new DateTimeZone('UTC'));

        // Session expired
        if ($current_time > $expiration_time) {
            UserSession::delete($session_id);
            header('Location: ' . $rootURL . $login_redirect);
            die();
        }

        // Extend session
        $config = include(CONFIG_FILE);
        $current_time->add(new DateInterval('PT' . $config['sessions']['max-age'] . 'S'));
        $expireTime = $current_time->format('Y-m-d H:i:s');
        $session->setExpires($expireTime);
        $session->save();

        $user = $session->getUser();
        // Check role permissions
        if (!hasRequiredRoles($user, $requiredRoles)) {
            unauthorized($user);
            return null;
        }
        // Set tenant context
        $currentTenantId = Tenant::getCurrentTenant($user->getId());
        if ($currentTenantId) {
            PostgresDB::setTenant($currentTenantId);
            PostgresDB::setRowSecurity(true);
        }
        return $user;
    }


    /**
     * @param array    $requiredRoles
     * @param bool     $allowAPI
     * @param string   $login_redirect
     * @return User|null
     */
    function get_session(array $requiredRoles = [], bool $allowAPI = false, string $login_redirect = '/login'): ?User {
        global $rootURL;

        // Set the redirect if accepts HTML
        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $acceptHeader = $_SERVER['HTTP_ACCEPT'];
            if (strpos($acceptHeader, 'text/html') !== false) {
                if ($_SERVER['REQUEST_URI'] != $rootURL . "/") {
                    $_SESSION['redirect'] = $_SERVER['REQUEST_URI'];
                }
            }
        }

        $apiKeysActive = Plugin::isPluginActiveByName("api_keys");
        $authorizationHeaderSet = isset($_SERVER["HTTP_AUTHORIZATION"]) && $_SERVER["HTTP_AUTHORIZATION"];
        $sessionExists = isset($_SESSION['caai_session_id']);
        $requiresRoles = count($requiredRoles) > 0;

        // Handle case: no session and roles required
        if (!$sessionExists && $requiresRoles) {
            return handleNoSessionWithRolesRequired($allowAPI, $apiKeysActive, $authorizationHeaderSet, $requiredRoles, $login_redirect);
        }

        // Handle case: no session and no roles required
        if (!$sessionExists && !$requiresRoles) {
            return handleNoSessionNoRolesRequired($allowAPI, $apiKeysActive, $authorizationHeaderSet);
        }

        // Handle case: session exists
        return handleExistingSession($requiredRoles, $login_redirect);
    }