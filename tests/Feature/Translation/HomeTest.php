<?php

use App\Livewire\TranslationWorkspace;

it('shows the translator Livewire page on home', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSeeLivewire(TranslationWorkspace::class)
        ->assertSee('Translate & Speak')
        ->assertSee('Any language → your target')
        ->assertSee('id="target-language"', false)
        ->assertSee('Japanese');
});
