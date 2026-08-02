<?php

use App\Services\TranslationSpeakerCatalog;

beforeEach(function () {
    configureAIProviderForTests(['fish_reference_id' => 'global-fish-ref']);
});

it('lists supported speaker modes with labels', function () {
    $catalog = app(TranslationSpeakerCatalog::class);

    expect($catalog->defaultMode())->toBe(TranslationSpeakerCatalog::MODE_SYSTEM)
        ->and($catalog->modes())->toBe(['system', 'custom'])
        ->and($catalog->options())->toBe([
            ['mode' => 'system', 'label' => 'System default'],
            ['mode' => 'custom', 'label' => 'Custom reference ID'],
        ])
        ->and($catalog->isSupportedMode('system'))->toBeTrue()
        ->and($catalog->isSupportedMode('custom'))->toBeTrue()
        ->and($catalog->isSupportedMode('preset'))->toBeFalse();
});

it('validates custom reference id format and length', function () {
    $catalog = app(TranslationSpeakerCatalog::class);

    expect($catalog->isValidCustomReferenceId('abc123'))->toBeTrue()
        ->and($catalog->isValidCustomReferenceId('voice_01-test'))->toBeTrue()
        ->and($catalog->isValidCustomReferenceId(''))->toBeFalse()
        ->and($catalog->isValidCustomReferenceId('-bad-start'))->toBeFalse()
        ->and($catalog->isValidCustomReferenceId('has spaces'))->toBeFalse()
        ->and($catalog->isValidCustomReferenceId(str_repeat('a', 129)))->toBeFalse();
});

it('normalizes invalid mode to the configured default', function () {
    $catalog = app(TranslationSpeakerCatalog::class);

    expect($catalog->normalizeSelection(null, null))->toBe([
        'mode' => 'system',
        'custom_reference_id' => null,
    ])->and($catalog->normalizeSelection('unknown', null))->toBe([
        'mode' => 'system',
        'custom_reference_id' => null,
    ]);
});

it('normalizes custom mode with missing or invalid id back to system', function () {
    $catalog = app(TranslationSpeakerCatalog::class);

    expect($catalog->normalizeSelection('custom', null))->toBe([
        'mode' => 'system',
        'custom_reference_id' => null,
    ])->and($catalog->normalizeSelection('custom', ''))->toBe([
        'mode' => 'system',
        'custom_reference_id' => null,
    ])->and($catalog->normalizeSelection('custom', ' bad id '))->toBe([
        'mode' => 'system',
        'custom_reference_id' => 'bad id',
    ]);
});

it('normalizes valid custom selection unchanged', function () {
    $catalog = app(TranslationSpeakerCatalog::class);

    expect($catalog->normalizeSelection('custom', ' visitor-voice-01 '))->toBe([
        'mode' => 'custom',
        'custom_reference_id' => 'visitor-voice-01',
    ]);
});

it('resolves visitor default from system mode to the global reference', function () {
    $catalog = app(TranslationSpeakerCatalog::class);

    expect($catalog->resolveVisitorDefault('system', null))->toBe('global-fish-ref')
        ->and($catalog->systemReferenceId())->toBe('global-fish-ref');
});

it('resolves visitor default from custom mode to the supplied reference', function () {
    $catalog = app(TranslationSpeakerCatalog::class);

    expect($catalog->resolveVisitorDefault('custom', 'my-custom-voice'))->toBe('my-custom-voice');
});

it('falls back to system reference when custom visitor default is missing', function () {
    $catalog = app(TranslationSpeakerCatalog::class);

    expect($catalog->resolveVisitorDefault('custom', null))->toBe('global-fish-ref');
});

it('applies precedence language override then visitor default then global', function () {
    config([
        'translation_languages.languages.ja.fish_reference_id' => 'lang-ja-voice',
    ]);

    $catalog = app(TranslationSpeakerCatalog::class);

    expect($catalog->resolveEffectiveReferenceId('ja', 'custom', 'visitor-voice'))->toBe('lang-ja-voice')
        ->and($catalog->resolveEffectiveReferenceId('en', 'custom', 'visitor-voice'))->toBe('visitor-voice')
        ->and($catalog->resolveEffectiveReferenceId('en', 'system', null))->toBe('global-fish-ref');
});
