<?php

use App\Http\Controllers\TranslationAudioController;
use App\Livewire\TranslationWorkspace;
use Illuminate\Support\Facades\Route;

Route::livewire('/', TranslationWorkspace::class)->name('home');

Route::get('/translations/{workflow}/audio', TranslationAudioController::class)
    ->middleware('signed')
    ->name('translations.audio');
