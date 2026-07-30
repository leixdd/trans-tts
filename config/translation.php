<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Translation workspace debug toolbar
    |--------------------------------------------------------------------------
    |
    | When enabled, the bug icon toolbar, per-turn Debug buttons, and worker /
    | stream log panels are available on the public translation page.
    |
    */

    'debug_toolbar_enabled' => filter_var(
        env('TRANSLATION_DEBUG_TOOLBAR_ENABLED', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

];
