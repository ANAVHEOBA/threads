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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

   'threads' => [
    'client_id' => env('THREADS_CLIENT_ID'),
    'client_secret' => env('THREADS_CLIENT_SECRET'),
    'redirect_uri' => env('THREADS_REDIRECT_URI'),
    'scope' => env('THREADS_SCOPE', 'threads_basic threads_content_publish'),
],


'mastodon' => [
    'instance_url' => env('MASTODON_INSTANCE_URL', 'https://mastodon.social'),
    'client_id' => env('MASTODON_CLIENT_ID'),
    'client_secret' => env('MASTODON_CLIENT_SECRET'),
    'redirect_uri' => env('MASTODON_REDIRECT_URI'),
],

];
