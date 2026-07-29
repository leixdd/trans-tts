<?php

/**
 * Allow-listed translation target languages (v1).
 *
 * Each entry may override TTS voice via fish_reference_id; null falls back to
 * services.ai_provider.fish_reference_id.
 */
return [

    'default' => 'ja',

    'languages' => [

        'ja' => [
            'label' => 'Japanese',
            'translation_prompt' => 'You are a translator. Translate the user\'s text into Japanese only. Return only the Japanese translation text with no explanations, notes, labels, or quotation marks.',
            'fish_reference_id' => null,
        ],

        'en' => [
            'label' => 'English',
            'translation_prompt' => 'You are a translator. Translate the user\'s text into English only. Return only the English translation text with no explanations, notes, labels, or quotation marks.',
            'fish_reference_id' => null,
        ],

        'zh' => [
            'label' => 'Chinese',
            'translation_prompt' => 'You are a translator. Translate the user\'s text into Mandarin Chinese only (Simplified Chinese characters). Return only the Chinese translation text with no explanations, notes, labels, or quotation marks.',
            'fish_reference_id' => null,
        ],

        'ko' => [
            'label' => 'Korean',
            'translation_prompt' => 'You are a translator. Translate the user\'s text into Korean only. Return only the Korean translation text with no explanations, notes, labels, or quotation marks.',
            'fish_reference_id' => null,
        ],

        'ceb' => [
            'label' => 'Cebuano',
            'translation_prompt' => 'You are a translator. Translate the user\'s text into Cebuano only. Return only the Cebuano translation text with no explanations, notes, labels, or quotation marks.',
            'fish_reference_id' => null,
        ],

    ],

];
