<?php

use App\Services\TranslationToneCatalog;

it('lists supported tone codes with labels and default', function () {
    $catalog = app(TranslationToneCatalog::class);

    expect($catalog->defaultCode())->toBe(TranslationToneCatalog::CODE_NORMAL)
        ->and($catalog->codes())->toBe([
            TranslationToneCatalog::CODE_NORMAL,
            TranslationToneCatalog::CODE_BUSINESS,
            TranslationToneCatalog::CODE_ACADEMIC,
        ])
        ->and($catalog->options())->toBe([
            ['code' => 'normal', 'label' => 'Normal Mode'],
            ['code' => 'business', 'label' => 'Business Mode'],
            ['code' => 'academic', 'label' => 'Academic Mode'],
        ])
        ->and($catalog->isSupported('normal'))->toBeTrue()
        ->and($catalog->isSupported('business'))->toBeTrue()
        ->and($catalog->isSupported('academic'))->toBeTrue()
        ->and($catalog->isSupported('casual'))->toBeFalse();
});

it('normalizes unknown or missing codes to the default', function () {
    $catalog = app(TranslationToneCatalog::class);

    expect($catalog->normalize(null))->toBe('normal')
        ->and($catalog->normalize(''))->toBe('normal')
        ->and($catalog->normalize('unknown'))->toBe('normal')
        ->and($catalog->normalize('business'))->toBe('business');
});

it('returns a non-empty prompt directive for each supported tone', function () {
    $catalog = app(TranslationToneCatalog::class);

    expect($catalog->promptDirective('normal'))
        ->toContain('natural, neutral')
        ->toContain('only the translation text');

    expect($catalog->promptDirective('business'))
        ->toContain('professional')
        ->toContain('only the translation text');

    expect($catalog->promptDirective('academic'))
        ->toContain('formal')
        ->toContain('only the translation text');
});

it('exposes validation rules for allow-listed tone codes', function () {
    $catalog = app(TranslationToneCatalog::class);

    expect($catalog->validationRules())->toHaveCount(3);
});
