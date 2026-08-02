<?php

/**
 * Session-scoped default Fish Audio speaker setting.
 *
 * Modes:
 * - system: use services.ai_provider.fish_reference_id
 * - custom: use a locally validated visitor-supplied reference ID
 *
 * Effective per-turn precedence (resolved at submission):
 * 1. Target language fish_reference_id override
 * 2. Visitor selected default (system or custom)
 * 3. Global AI_PROVIDER_FISH_REFERENCE_ID
 */
return [

    'default_mode' => 'system',

    'modes' => [

        'system' => [
            'label' => 'System default',
        ],

        'custom' => [
            'label' => 'Custom reference ID',
        ],

    ],

    'custom_reference_id' => [
        'max_length' => 128,
        // Opaque provider token: letters/digits, optional underscore/hyphen after first char.
        'pattern' => '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/',
    ],

];
