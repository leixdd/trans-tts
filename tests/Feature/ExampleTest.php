<?php

use App\Livewire\TranslationWorkspace;

it('returns a successful response for home', function () {
    $this->get('/')
        ->assertOk()
        ->assertSeeLivewire(TranslationWorkspace::class);
});
