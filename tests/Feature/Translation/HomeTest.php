<?php

use App\Livewire\TranslationWorkspace;

it('shows the translator Livewire page on home', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSeeLivewire(TranslationWorkspace::class)
        ->assertSee('Translate & Speak')
        ->assertDontSee('Any language → your target')
        ->assertDontSee('Chat-style translation with speech playback history.')
        ->assertSee('id="target-language"', false)
        ->assertSee('Japanese');
});
