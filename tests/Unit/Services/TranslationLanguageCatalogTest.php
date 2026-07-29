<?php

use App\Services\TranslationLanguageCatalog;

it('lists the v1 allow-listed language codes with Japanese as default', function () {
    $catalog = app(TranslationLanguageCatalog::class);

    expect($catalog->defaultCode())->toBe('ja')
        ->and($catalog->codes())->toBe(['ja', 'en', 'zh', 'ko'])
        ->and($catalog->label('zh'))->toBe('Chinese')
        ->and($catalog->normalize(null))->toBe('ja')
        ->and($catalog->normalize('xx'))->toBe('ja')
        ->and($catalog->isSupported('en'))->toBeTrue()
        ->and($catalog->isSupported('fr'))->toBeFalse();
});

it('returns a non-empty translation prompt for each catalog language', function () {
    $catalog = app(TranslationLanguageCatalog::class);

    foreach ($catalog->codes() as $code) {
        expect($catalog->translationPrompt($code))->not->toBeEmpty();
    }
});
