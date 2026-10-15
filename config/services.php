<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google_drive' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
        'affiliates_folder_id' => env('GOOGLE_DRIVE_AFFILIATES_FOLDER_ID'),
    ],

    'finance_dashboard' => [
        // Opened from "Finance" in the panel navigation.
        'url' => env('FINANCE_DASHBOARD_URL', 'https://finance-app-nu-orpin-25.vercel.app'),
    ],

    'esignatures' => [
        'secret_token' => env('ESIGNATURES_SECRET_TOKEN'),
        // The ID shown when editing the affiliate contract template in eSignatures.
        'template_id' => env('ESIGNATURES_TEMPLATE_ID'),
        // Signs every contract for e-Skool automatically, after the partner signed.
        'eskool_signer' => [
            'name' => env('ESIGNATURES_ESKOOL_SIGNER_NAME', 'Gairo Juliana'),
            'email' => env('ESIGNATURES_ESKOOL_SIGNER_EMAIL', 'ops@e-skool.nl'),
        ],
        // Test contracts are free and marked as demo; set to false in production. Empty means test mode.
        'test_mode' => in_array(env('ESIGNATURES_TEST_MODE'), [null, ''], true) || filter_var(env('ESIGNATURES_TEST_MODE'), FILTER_VALIDATE_BOOL),
    ],

    'mollie' => [
        'key' => env('MOLLIE_KEY'),
    ],

    'vies' => [
        // Check EU VAT numbers with the European Commission's VIES service when saving an affiliate.
        'enabled' => (bool) env('VIES_VALIDATION', true),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
