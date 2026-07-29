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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'novita' => [
        'api_key' => env('NOVITA_API_KEY'),
        'chat_base_url' => env('NOVITA_CHAT_BASE_URL', 'https://api.novita.ai/openai/v1'),
        'tts_endpoint' => env(
            'NOVITA_TTS_ENDPOINT',
            'https://api.novita.ai/v3/fish-audio-s2-pro-text-to-speech',
        ),
        'translation_model' => env('NOVITA_TRANSLATION_MODEL', 'google/gemma-4-31b-it'),
        'fish_reference_id' => env('NOVITA_FISH_REFERENCE_ID'),
        'timeout' => (int) env('NOVITA_TIMEOUT', 60),
        'retention_days' => (int) env('NOVITA_RETENTION_DAYS', 30),
        'history_limit' => (int) env('NOVITA_HISTORY_LIMIT', 50),
        'signed_url_minutes' => (int) env('NOVITA_SIGNED_URL_MINUTES', env('NOVITA_RETENTION_MINUTES', 60)),
        'stream_poll_seconds' => (float) env('NOVITA_STREAM_POLL_SECONDS', 0.5),
        'stream_heartbeat_seconds' => (float) env('NOVITA_STREAM_HEARTBEAT_SECONDS', 15),
        'stream_max_seconds' => (float) env('NOVITA_STREAM_MAX_SECONDS', 300),
        'user_agent' => env('NOVITA_USER_AGENT', 'tts-app/1.0'),
    ],

];
