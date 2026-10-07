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

    'mollie' => [
        // Creates Mollie customers (Customers API) for invoiced affiliates. Off: those customers land in
        // Sales → Klanten, not in Invoicing → Klanten.
        'sync_customers' => (bool) env('MOLLIE_SYNC_CUSTOMERS', false),
        'key' => env('MOLLIE_KEY'),

        // On: "Generate invoice" creates and sends the invoice through the Sales Invoices API.
        // Off: it opens Mollie Invoicing → Facturen to create the invoice there.
        'create_invoices_via_api' => (bool) env('MOLLIE_CREATE_INVOICES_VIA_API', false),

        // Used to link to the Mollie dashboard, e.g. org_19111288.
        'organization_id' => env('MOLLIE_ORGANIZATION_ID'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
