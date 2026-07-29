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

    'ai_provider' => [
        'api_key' => env('AI_PROVIDER_API_KEY'),
        'chat_base_url' => env('AI_PROVIDER_CHAT_BASE_URL', 'https://api.novita.ai/openai/v1'),
        'tts_endpoint' => env(
            'AI_PROVIDER_TTS_ENDPOINT',
            'https://api.novita.ai/v3/fish-audio-s2-pro-text-to-speech',
        ),
        'translation_model' => env('AI_PROVIDER_TRANSLATION_MODEL', 'google/gemma-4-31b-it'),
        'fish_reference_id' => env('AI_PROVIDER_FISH_REFERENCE_ID'),
        'timeout' => (int) env('AI_PROVIDER_TIMEOUT', 60),
        'retention_days' => (int) env('AI_PROVIDER_RETENTION_DAYS', 30),
        'history_limit' => (int) env('AI_PROVIDER_HISTORY_LIMIT', 50),
        'signed_url_minutes' => (int) env('AI_PROVIDER_SIGNED_URL_MINUTES', env('AI_PROVIDER_RETENTION_MINUTES', 60)),
        'stream_poll_seconds' => (float) env('AI_PROVIDER_STREAM_POLL_SECONDS', 0.5),
        'stream_heartbeat_seconds' => (float) env('AI_PROVIDER_STREAM_HEARTBEAT_SECONDS', 15),
        'stream_max_seconds' => (float) env('AI_PROVIDER_STREAM_MAX_SECONDS', 300),
        'user_agent' => env('AI_PROVIDER_USER_AGENT', 'tts-app/1.0'),
    ],

];
