<?php
// ============================================================
// EXAMPLE FILE — CAT-Talk/frontend/config.php
// Copy this file into the CAT-Talk/frontend folder, rename it
// to "config.php", then replace every CHANGE-ME value.
// Every value marked CHANGE-ME must be replaced before the site
// will work.
// ============================================================
    return [
        // --- Database connection ---------------------------------
        // These must match the values in your .env file.
        // Leave 'host' as 'postgres' — that is the internal name.
        'db' => [
            'host'              => 'postgres',
            'name'              => 'speakez',
            'user'              => 'speakez',
            'pass'              => 'CHANGE-ME-database-password',
            'port'              => 5432
        ],
        // --- Login (CiLogon) --------------------------------------
        // 1. Go to https://cilogon.org/oauth2/register and register a
        //    new application.
        // 2. For "Application URL" enter your site address, e.g.
        //    https://speakez.example.org
        // 3. Put the clientId and clientSecret it gives you below.
        // 4. redirectUri is your site address plus /callback
        'oauth2' => [
            'clientId'                  => 'CHANGE-ME-cilogon-client-id',
            'clientSecret'              => 'CHANGE-ME-cilogon-client-secret',
            'redirectUri'               => 'https://speakez.example.org/callback',
            'scopes'                    => ['openid', 'email', 'profile', 'org.cilogon.userinfo'],
            'idphint'                   => ['https://ukidp.uky.edu/idp/shibboleth', 'https://google.com/accounts/o8/id', 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize', 'https://github.com/login/oauth/authorize', 'https://orcid.org/oauth/authorize'],
            'initialidp'                => 'https://ukidp.uky.edu/idp/shibboleth',
        ],
        // How long a login lasts (6 hours). No need to change.
        'sessions' => [
            'max-age'   => 21600,
        ],
        // Colors and title shown in the website header.
        "tenants" => [
            "styling" => [
                "defaults" => [
                    "title_text" => "SpeakEZ",
                    "title_text_color" => "#FFFFFF",
                    "navbar_color" => "#1a48aa",
                    "navbar_menu_color" => "#FFFFFF",
                    "menu_active_dropdown_text_color" => "#FFFFFF",
                    "menu_active_dropdown_bg_color" => "#0d6efd",
                    "button_primary" => "#0d6efd",
                    "button_secondary" => "#6c757d",
                    "button_success" => "#198754",
                    "button_danger" => "#dc3545",
                    "button_warning" => "#ffc107",
                    "button_info" => "#0dcaf0",
                    "button_light" => "#f8f9fa",
                    "button_dark" => "#343a40",
                ],
            ],
        ],
        // "development" shows error details on screen. Change to
        // "production" once everything works.
        'environment' => "development",
        // Leave blank unless the site lives under a sub-path.
        'rootURL' => '',
        // --- File storage (S3 / MinIO) -----------------------------
        // The storage server you set up in Step 2 of SETUP.md.
        // ENDPOINT uses the server's own IP, NOT localhost.
        's3' => [
            'top_level_bucket' => '',
            'bucket'    => 'speakez-audio',
            'output_bucket' => 'speakez-output',
            'endpoint'  => 'http://192.168.1.50:9000',
            'id'        => 'CHANGE-ME-minio-user',
            'secret'    => 'CHANGE-ME-minio-password'
        ],
        // --- Job-processing connection ------------------------------
        // backend_server: address of the transcription API from Step 4
        // (same server, port 5050). Use the server IP, NOT localhost.
        // apiKey: Key A from SETUP.md section 2.2 (the same plain text
        //         goes into the job API's config.ini and the workers'
        //         config.ini).
        // api_key: Key B from SETUP.md section 2.2 (the same plain text
        //          goes into the job API's config.ini and the workers'
        //          config.ini as updates_api_key).
        'backend_server' => 'http://192.168.1.50:5050',
        'apiKey'         => 'SpeakEZ-Job-Key-CHANGE-ME-0001',
        'api_key'        => 'SpeakEZ-Updates-Key-CHANGE-ME-0002',
];
