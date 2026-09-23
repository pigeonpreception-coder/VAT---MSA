<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | VAT-MSA feature flags
    |--------------------------------------------------------------------------
    |
    | enable_synthetic_counterparty_trust: opt-in for App\Support\Business\
    | CounterpartyTrustGate::syntheticEnabled() to accept SYNTHETIC_VALID
    | counterparty trust on the staging deployment only (local and testing
    | already always accept it; production never does regardless of this
    | flag). See 05-security/issue3-counterparty-trust-boundary.md.
    |
    */

    'vat_msa' => [
        'enable_synthetic_counterparty_trust' => env('VAT_MSA_ENABLE_SYNTHETIC_COUNTERPARTY_TRUST', false),
    ],

];
