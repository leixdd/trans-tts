<?php

declare(strict_types=1);

/**
 * Seeds deterministic completed turns for Playwright playback acceptance.
 * Outputs JSON with visitor cookie + turn ids for global-setup.js.
 */

const E2E_VISITOR_ID = 'e2e00001-0000-4000-8000-000000000001';

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\TranslationTurn;
use App\Services\AnonymousVisitor;
use App\Services\TranslationWorkflowStore;

TranslationTurn::query()->where('visitor_id', E2E_VISITOR_ID)->delete();

/** @var TranslationWorkflowStore $store */
$store = app(TranslationWorkflowStore::class);
/** @var AnonymousVisitor $visitor */
$visitor = app(AnonymousVisitor::class);

$wav = 'RIFF'.str_repeat("\0", 100);

$first = $store->create(E2E_VISITOR_ID, 'First playback turn');
$store->setTranslation($first['id'], 'First translation');
$store->storeAudio($first['id'], $wav);
$store->markCompleted($first['id']);

$second = $store->create(E2E_VISITOR_ID, 'Second playback turn');
$store->setTranslation($second['id'], 'Second translation');
$store->storeAudio($second['id'], $wav);
$store->markCompleted($second['id']);

echo json_encode([
    'visitorId' => E2E_VISITOR_ID,
    'turnIds' => [$first['id'], $second['id']],
    'cookieName' => AnonymousVisitor::COOKIE_NAME,
    'cookieValue' => $visitor->makeCookie(E2E_VISITOR_ID)->getValue(),
], JSON_THROW_ON_ERROR);
