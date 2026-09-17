<?php

use JetBrains\PhpStorm\NoReturn;

require_once __DIR__ . '/../utilities/CiLogonProvider.php';
include_once MODELS_DIR . 'User.php';
include_once MODELS_DIR . 'UserSession.php';
$rootURL = $config['rootURL'];

class RootController {
    public static function index(User $user): void {
        global $rootURL;
        if (Plugin::isPluginActiveByName("projects")) {
            header('Location: ' . $rootURL.'/projects');
        } else {
            require VIEWS_DIR . 'home.php';
        }
    }

    public static function unauthorized(User $user): void {
        require VIEWS_DIR . 'no_access.php';
    }

    public static function no_access(User $user): void {
        require VIEWS_DIR . 'no_access.php';
    }

    public static function userGuide(?User $user): void {
        require VIEWS_DIR . 'user_guide.php';
    }

    #[NoReturn] public static function login(): void {
        $provider = CiLogonProvider::getProvider();
        $authorizationUrl = $provider->getAuthorizationUrl();
        header('Location: ' . $authorizationUrl);
        die();
    }

    /**
     * @throws Exception
     */
    public static function callback(): void {
        $config = require CONFIG_FILE;
        $cilogonOAuth = CiLogonProvider::getProvider();
        global $rootURL;

        if (!isset($_GET['code']) || !isset($_GET['state'])) {
            // If code or state is missing, redirect to log in
            $authorizationUrl = $cilogonOAuth->getAuthorizationUrl();
            header('Location: ' . $authorizationUrl);
            die();
        }

        $state = $_GET['state'];

        // Validate state
        if (!$cilogonOAuth->validateState($state)) {
            // Invalid state parameter
            // CILogon link likely expired. Send them back to log in
            header('Location: ' . $rootURL . '/login');
            die();
        }

        $authorizationCode = $_GET['code'];
        $accessToken = $cilogonOAuth->getAccessToken($authorizationCode);

        $info = $cilogonOAuth->getUserInfo();

        $user = User::withId($info['id']);
        if (is_null($user)) {
            $user = User::create($info);
        } else {
            $user = User::updateProfile($user->getId(), $info);
        }
        if (is_null($user)) {
            self::no_access($user);
            die();
        }
        $session = UserSession::create(session_id(), $user, $accessToken);
        if (is_null($session)) {
            self::no_access($user);
            die();
        }
        self::post_login_redirect();
    }

    public static function logout($redirect = null, $logoutWithProvider = true): void {
        UserSession::delete(session_id());
        session_destroy();
        session_start();
        session_regenerate_id();
        $_SESSION['redirect'] = $redirect;
        if ($logoutWithProvider) {
            $cilogon = new CiLogonProvider();
            header('Location: ' . $cilogon->getLogoutUrl());
            die();
        }
    }


    private static function post_login_redirect(): void {
        global $rootURL;
        if (!isset($_SESSION['redirect']))
            header('Location: '.$rootURL.'/');
        else {
            $redirect = $_SESSION['redirect'];
            $_SESSION['redirect'] = null;
            header('Location: ' .$redirect);
        }
        die();
    }

    public static function healthCheck(): void {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }
}