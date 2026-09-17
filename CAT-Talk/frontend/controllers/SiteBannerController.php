<?php

require_once __DIR__ . '/../utilities/db.php';
include_once MODELS_DIR . 'User.php';
include_once MODELS_DIR . 'SiteBanner.php';


class SiteBannerController {
    public static function index(User $user): void {
        global $rootURL;
        $banner = SiteBanner::load();
        if (!$banner) {
            $banner = new SiteBanner();
        }
        $isOn = $banner->getIsOn();
        $message = $banner->getMessage();

        require BANNER_VIEWS_DIR . 'index.php';
    }

    public static function setBanner(User $user): void {
        $jsonData = file_get_contents('php://input');
        $data = json_decode($jsonData, true);


        if (!array_key_exists('message', $data) || !array_key_exists('is_on', $data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing Message or Status in request']);
            return;
        }

        $banner = SiteBanner::load();
        if (!$banner) {
            $banner = new SiteBanner();
        }

        $banner->setMessage($data['message']);
        // Robustly handle boolean, string, or int for is_on
        $isOn = $data['is_on'] ? true : false;

        $banner->setIsOn($isOn);
        $banner->save();

        echo json_encode(['success' => true]);
    }
}