<?php

/**
 * Allow-listed translation tone modes (v1).
 *
 * Each turn snapshots a tone code at submission. Prompt directives are
 * appended to the language translation prompt; the response must remain
 * translation-only (no explanations, notes, labels, or quotation marks).
 */
return [

    'default' => 'normal',

    'tones' => [

        'normal' => [
            'label' => 'Normal Mode',
            'prompt_directive' => 'Use a natural, neutral style. Still return only the translation text with no explanations, notes, labels, or quotation marks.',
        ],

        'business' => [
            'label' => 'Business Mode',
            'prompt_directive' => 'Use concise, professional, workplace-appropriate wording. Still return only the translation text with no explanations, notes, labels, or quotation marks.',
        ],

        'academic' => [
            'label' => 'Academic Mode',
            'prompt_directive' => 'Use formal, precise, scholarly wording. Still return only the translation text with no explanations, notes, labels, or quotation marks.',
        ],

    ],

];
