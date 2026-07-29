<?php

use App\Services\AnonymousVisitor;
use App\Services\TranslationWorkflowStore;

beforeEach(function () {
    configureNovitaForTests([
        'stream_poll_seconds' => 0.05,
        'stream_heartbeat_seconds' => 10,
        'stream_max_seconds' => 1,
    ]);
});

it('denies the SSE feed to a non-owning visitor', function () {
    $store = app(TranslationWorkflowStore::class);
    $turn = $store->create('owner-visitor', 'Hello');

    $this->withUnencryptedCookie(AnonymousVisitor::COOKIE_NAME, 'other-visitor')
        ->get(route('translations.stream', ['workflow' => $turn['id']]))
        ->assertForbidden();
});

it('streams a terminal snapshot for a completed owned turn', function () {
    $visitorId = '77777777-7777-4777-8777-777777777777';
    $store = app(TranslationWorkflowStore::class);
    $turn = $store->create($visitorId, 'Hello');
    $store->setTranslation($turn['id'], 'こんにちは');
    $store->storeAudio($turn['id'], fakeWavBytes());
    $store->markCompleted($turn['id']);

    $response = $this->withUnencryptedCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->get(route('translations.stream', ['workflow' => $turn['id']]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
        ->assertHeader('X-Accel-Buffering', 'no');

    $body = $response->streamedContent();

    expect($body)->toContain('event: terminal')
        ->and($body)->toContain('"status":"completed"')
        ->and($body)->toContain('"translation":"こんにちは"')
        ->and($body)->toContain('"terminal":true');
});

it('streams an initial snapshot and reconnects after the bounded lifetime', function () {
    $visitorId = '88888888-8888-4888-8888-888888888888';
    $store = app(TranslationWorkflowStore::class);
    $turn = $store->create($visitorId, 'Hello');
    $store->markStatus($turn['id'], 'translating');
    $store->setTranslation($turn['id'], 'こん');

    $response = $this->withUnencryptedCookie(AnonymousVisitor::COOKIE_NAME, $visitorId)
        ->get(route('translations.stream', ['workflow' => $turn['id']]));

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)->toContain('event: snapshot')
        ->and($body)->toContain('"status":"translating"')
        ->and($body)->toContain('"translation":"こん"')
        ->and($body)->toContain('event: reconnect')
        ->and($body)->toContain('"reason":"max_duration"');
});

it('exposes a stream snapshot through the workflow store', function () {
    $store = app(TranslationWorkflowStore::class);
    $turn = $store->create('visitor-stream', 'Hello');
    $store->markStatus($turn['id'], 'translating');
    $store->appendStreamDebug(
        $turn['id'],
        '部分',
        '{"choices":[{"delta":{"content":"部分"}}]}',
    );

    $snapshot = $store->streamSnapshot($turn['id'], 'visitor-stream');

    expect($snapshot)->toMatchArray([
        'id' => $turn['id'],
        'status' => 'translating',
        'translation' => '部分',
        'error' => null,
        'terminal' => false,
    ]);
});

it('keeps the configurable Novita HTTP timeout intact', function () {
    configureNovitaForTests(['timeout' => 90]);

    expect(config('services.novita.timeout'))->toBe(90);
});

it('includes stream_url for in-flight public payloads', function () {
    $visitorId = '99999999-9999-4999-8999-999999999999';
    $store = app(TranslationWorkflowStore::class);
    $turn = $store->create($visitorId, 'Hello');

    $payload = $store->publicStatus($turn['id'], $visitorId);

    expect($payload['stream_url'])->toBe(route('translations.stream', ['workflow' => $turn['id']]));
});
