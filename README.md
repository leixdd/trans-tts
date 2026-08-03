# Translate & Speak (multi-target TTS)

Public chat-style web app that accepts source text in any language, translates it to a chosen target via AIProvider (`google/gemma-4-31b-it`), and synthesizes speech with Fish Audio S2 Pro (WAV). v1 targets are Japanese (default), English, Chinese, Korean, and Cebuano (`config/translation_languages.php`). Each turn is queued in the background; the Livewire UI shows an ordered history, relays progressive translation over an application SSE feed (with low-frequency polling fallback), reveals translated text with a writing animation, and autoplays completed audio in submission order (FIFO).

**Stack:** Laravel 13, Livewire 4, Laravel Octane (FrankenPHP), Bun + Vite + Tailwind CSS 4.

## Requirements

- PHP 8.4+
- [Composer](https://getcomposer.org/)
- [Bun](https://bun.sh/) — **never use npm or Node.js** for frontend tooling in this project
- SQLite (default) or another Laravel-supported database

## Environment

Copy `.env.example` to `.env`, generate an app key, and set AIProvider credentials:

```bash
cp .env.example .env
php artisan key:generate
```

### Required

| Variable | Purpose |
|----------|---------|
| `APP_KEY` | Laravel encryption key |
| `AI_PROVIDER_API_KEY` | AIProvider API key for translation and TTS |
| `AI_PROVIDER_FISH_REFERENCE_ID` | System-default Fish Audio voice (used when no language override or visitor custom ID applies) |

### Optional (defaults in `config/services.php`)

| Variable | Default |
|----------|---------|
| `AI_PROVIDER_CHAT_BASE_URL` | `https://api.novita.ai/openai/v1` |
| `AI_PROVIDER_TTS_ENDPOINT` | `https://api.novita.ai/v3/fish-audio-s2-pro-text-to-speech` |
| `AI_PROVIDER_TRANSLATION_MODEL` | `google/gemma-4-31b-it` |
| `AI_PROVIDER_TIMEOUT` | `60` (seconds; outbound AIProvider HTTP/SSE client timeout — adjustable) |
| `AI_PROVIDER_RETENTION_DAYS` | `30` (anonymous chat history lifetime) |
| `AI_PROVIDER_HISTORY_LIMIT` | `50` (max turns kept per visitor) |
| `AI_PROVIDER_SIGNED_URL_MINUTES` | `60` (temporary audio URL lifetime) |
| `AI_PROVIDER_STREAM_POLL_SECONDS` | `0.5` (app SSE observation interval) |
| `AI_PROVIDER_STREAM_HEARTBEAT_SECONDS` | `15` (app SSE heartbeat interval) |
| `AI_PROVIDER_STREAM_MAX_SECONDS` | `300` (app SSE connection lifetime before reconnect) |
| `AI_PROVIDER_USER_AGENT` | `tts-app/1.0` |

Octane settings (`OCTANE_SERVER`, `OCTANE_HOST`, `OCTANE_PORT`, etc.) are documented in `.env.example`.

> **Security:** Do not copy AIProvider API keys from other projects (e.g. CIELAI) into this repository, Docker images, fixtures, or documentation. Use project-specific credentials and rotate any keys that may have been exposed elsewhere.

## Local development

One-time setup:

```bash
composer install
bun install
bun run build
php artisan migrate
```

Run **three processes** (separate terminals). Workflows stay `queued` without a queue worker; expired artifacts are not cleaned up without the scheduler.

```bash
# Web (Octane + FrankenPHP)
php artisan octane:frankenphp --host=127.0.0.1 --port=8000

# Background translation/TTS
php artisan queue:work --tries=3 --timeout=120

# Hourly translations:prune (or use schedule:work)
php artisan schedule:work
```

Open [http://127.0.0.1:8000](http://127.0.0.1:8000).

`php artisan serve` works as a dev fallback for the Livewire page, but production targets Octane/FrankenPHP; the queue and scheduler are still required.

## Docker

Ensure `.env` exists with `APP_KEY`, `AI_PROVIDER_API_KEY`, and `AI_PROVIDER_FISH_REFERENCE_ID`:

```bash
docker compose up --build
```

Services:

| Service | Role |
|---------|------|
| `app` | Octane FrankenPHP on port `${OCTANE_PORT:-8000}` |
| `queue` | Processes translation/TTS jobs |
| `scheduler` | Runs `schedule:work` (hourly `translations:prune`) |
| `migrate` | One-shot SQLite init and `migrate --force` |

Shared volumes persist the SQLite database and private WAV files under `storage/app`.

## Chat input and history

In the source textarea, **Enter** submits Translate & Speak; **Shift+Enter** inserts a newline. The target language select next to the submit button defaults to Japanese and is remembered for the browser session (`translation_target_language`). An inline **translation tone** select sits beside the language selector (see [Translation tone](#translation-tone) below). A compact **Audio settings** control sits next to those controls (see [Output device routing](#output-device-routing) and [Default speaker](#default-speaker) below). Each assistant bubble shows the turn’s target language.

Turns are stored in the `translation_turns` table (including `target_language`) and keyed by an anonymous encrypted `tts_visitor` cookie (not a user account). Each visitor keeps at most `AI_PROVIDER_HISTORY_LIMIT` turns for `AI_PROVIDER_RETENTION_DAYS`. Private WAV files live under `storage/app/private/translation-audio` and are streamed only through signed URLs for the owning visitor.

While a turn is in flight, the assistant bubble shows a writing indicator. When translated text arrives (SSE snapshot or Livewire morph), it types in grapheme-by-grapheme (`resources/js/translation-typing.js`). History restored on first paint is shown instantly (no replay).

## Translation tone

The inline **translation tone** select (`#translation-tone`) sits beside the target language selector. Three fixed modes control how the AIProvider translation prompt is styled:

| Mode | Effect |
|------|--------|
| **Normal Mode** (default) | Natural, neutral wording |
| **Business Mode** | Concise, professional, workplace-appropriate wording |
| **Academic Mode** | Formal, precise, scholarly wording |

The choice uses the Laravel session lifecycle (`translation_tone`) and survives page reloads within the same browser session. It is not stored in browser `localStorage` or tied to a user account.

On submit, the selected tone code is snapshotted on each turn (`translation_tone`) before queue dispatch. Changing the selector later does not alter already queued or completed turns. Legacy turns without an explicit tone normalize to Normal Mode.

Tone affects translation prompt composition only (a directive appended alongside the language instruction). TTS voice, playback FIFO, and SSE behavior are unchanged. Exact stylistic quality is **prompt-driven** and depends on the translation provider.

Tone codes are omitted from chat history, public turn payloads, and the UI in this version. Mode definitions and prompt directives live in `config/translation_tones.php`.

## Audio playback

Autoplay uses a browser FIFO queue ordered by submission time (`resources/js/translation-playback.js`). Parallel workers may finish later chats first; the browser buffers those clips until every earlier turn has finished playing or failed. Only one clip plays at a time.

Completed turns show a custom **Play** / **Stop** control (no native `<audio controls>` / volume UI). That control appears only when the turn’s audio is ready **and** every earlier turn is settled. While another clip is playing, other turns’ controls stay disabled so manual playback cannot jump the FIFO queue. **Stop** settles the active clip and advances the FIFO queue to the next turn (not a resumable pause). Restored history is playable manually but does not autoplay again on page load. If the browser blocks autoplay, a **Play now** control appears for the blocked turn and resumes the same ordered queue.

The `translations:prune` command removes expired turns and orphaned audio files; it is scheduled hourly in `routes/console.php`.

## Output device routing

Chromium users can route this app’s TTS playback to a specific OS-exposed output (earphones, external interfaces, or virtual loopback endpoints) without changing the system-wide default.

Open **Audio settings** beside the language selector. The **Output device** section shows the current selection, a **Choose output device** action (Chromium’s native picker — the app never silently selects hardware), and **Use system default** to clear the preference.

### Browser support and permissions

- **Chromium success path:** requires a [secure context](https://developer.mozilla.org/en-US/docs/Web/Security/Secure_Contexts) (HTTPS or `localhost`) and `HTMLMediaElement.setSinkId`. Chrome/Brave/Edge list outputs via `enumerateDevices` (one-time microphone permission unlocks labels); Firefox uses the native `selectAudioOutput` picker when available.
- **User activation:** choosing a device requires a click on **Choose output device**; the browser may prompt for `speaker-selection` permission.
- **Unsupported browsers** (Safari, Firefox, insecure HTTP): TTS still plays on the OS default; output controls are disabled with an explanatory label.

### Privacy and persistence

The chosen device’s opaque ID and display label are stored in browser `localStorage` under `tts_audio_output_device` only. They are never sent to Laravel, Livewire, analytics, logs, URLs, or turn payloads. Clearing site data or using **Use system default** removes the preference.

### Routing behavior

- Every newly created TTS `Audio` element receives `setSinkId` **before** `play()` (`resources/js/audio-output-device.js` consumed by `resources/js/translation-playback.js`).
- FIFO order, Stop-and-advance, autoplay-block recovery, and signed audio URLs are unchanged.
- **Next-clip activation:** changing the output device while a clip is playing does not reroute or restart that clip; the new preference applies from the **next** clip onward.
- **Fallback:** if the saved device is disconnected, permission is revoked, or sink assignment fails, the preference clears, playback continues on the system default, and a non-blocking notice appears: *Selected output device is unavailable. Using system default.*
- Cancelling the browser picker preserves the prior selection without an error.

### Virtual loopback

Virtual loopback tools (e.g. BlackHole, VB-Cable) appear only when the OS and driver expose them as a **Chromium-selectable audio output** endpoint. Input-only or hidden loopback devices cannot be chosen.

### Manual smoke checklist (real hardware)

CI mocks the browser APIs and cannot prove OS-level routing. With physical hardware:

1. **Prerequisites:** Chromium, HTTPS or localhost, `speaker-selection` allowed, earphones or an OS-exposed virtual loopback listed as an **output** in system sound settings.
2. **Choose earphones:** Audio settings → **Choose output device** → pick earphones → confirm the label updates.
3. **Route TTS:** Submit a translation; confirm audio plays on earphones (not built-in speakers).
4. **Persist:** Reload; confirm the label is restored and new TTS clips still route to earphones.
5. **Change while playing:** Start a clip; switch to a different output; confirm the **current** clip is unchanged and the **next** clip uses the new device.
6. **Reset:** **Use system default**; confirm storage is cleared and subsequent clips use the OS default.
7. **Disconnect / stale device:** Unplug earphones or revoke permission; play TTS; confirm the fallback notice and system-default playback.
8. **Virtual loopback (optional):** If the OS exposes a loopback **output**, select it and confirm TTS appears in the loopback consumer app.

## Default speaker

The **Default speaker** subsection in **Audio settings** chooses the Fish Audio voice for **new** turns: **System default** or **Custom reference ID**. The choice uses the Laravel session lifecycle (`translation_speaker_mode`, `translation_speaker_custom_reference_id`).

Custom reference IDs are validated locally (required when in custom mode, bounded length, alphanumeric token format); there is no provider-side entitlement preflight. Syntactically valid but unknown IDs may fail asynchronously during TTS synthesis.

On submit, the effective Fish Audio voice resolves in this order:

1. Target-language `fish_reference_id` override from `config/translation_languages.php`
2. Visitor-selected default (system or custom)
3. Global `AI_PROVIDER_FISH_REFERENCE_ID`

The resolved reference ID is captured privately on each turn (`speaker_reference_id`) before queue dispatch; changing speaker settings later does not alter already queued turns. Reference IDs are omitted from chat history, public turn payloads, user-facing errors, and worker logs.

Per-language translation prompts and optional Fish Audio voice overrides live in `config/translation_languages.php`. Session-scoped speaker modes and custom-ID rules live in `config/translation_speakers.php`.

## Progressive translation (SSE relay)

AIProvider chat completions are still consumed as SSE inside the queue worker. Chunks are persisted on the turn (`translation` / `stream_debug`). The browser does **not** talk to AIProvider directly.

For each in-flight turn, the owning visitor can open:

`GET /translations/{workflow}/stream`

That application SSE feed emits idempotent snapshots (`snapshot` / `terminal`), plus `heartbeat` keep-alives and a bounded `reconnect` close after `AI_PROVIDER_STREAM_MAX_SECONDS`. `EventSource` reconnects and reads the latest database snapshot — there is no separate event-log table.

`AI_PROVIDER_TIMEOUT` still applies to the outbound AIProvider HTTP client. Raising it is how you allow longer AIProvider streams; the app SSE relay only delivers chunks already received and stored by the worker.

Livewire keeps a slower `wire:poll` fallback (5s) so missed or dropped browser streams still converge. On a `terminal` event the page performs a canonical Livewire refresh so audio URLs and FIFO playback stay authoritative.

Because each SSE connection holds an Octane worker for its lifetime, feeds are visitor-owned, heartbeat regularly, and self-terminate after the configured max duration.

Manual prune:

```bash
php artisan translations:prune
```

## Testing

```bash
composer test          # lint + PHPStan + Pest
vendor/bin/pest        # feature tests only
composer lint:check    # Pint dry-run
composer types:check   # PHPStan (512M memory limit)
bun test resources/js  # FIFO playback, output routing, typing reveal (Bun)
bun run build          # production asset build
bun run test:e2e:install   # once — installs Playwright Chromium
bun run test:e2e           # browser playback + audio settings acceptance (starts webServer)
bun run test:e2e tests/e2e/output-device.spec.js  # output device routing acceptance
bun run test:e2e tests/e2e/speaker-setting.spec.js  # default speaker acceptance
bun run test:e2e tests/e2e/translation-tone.spec.js  # translation tone acceptance
```

Playwright e2e tests seed a deterministic playback fixture (run `php artisan migrate` first so the dev database includes all schema columns, including `speaker_reference_id` and `translation_tone` on `translation_turns`), start `php artisan serve` on port 8765, and assert Play/Stop icon visibility, FIFO stop-and-advance behavior, audio settings panel states, output-device routing with mocked `selectAudioOutput` / `setSinkId`, and translation tone selector behavior. Override the base URL with `PLAYWRIGHT_BASE_URL`; set `CI=1` to disable server reuse and enable one retry. Failure artifacts land under `tests/e2e/test-results/`; HTML report under `tests/e2e/playwright-report/`.

Focused speaker backend coverage:

```bash
vendor/bin/pest tests/Unit/Services/TranslationSpeakerCatalogTest.php \
  tests/Feature/Translation/StartTranslationWorkflowTest.php \
  tests/Feature/Translation/TranslationWorkspaceLivewireTest.php \
  tests/Feature/Translation/TranslateAndSynthesizeSpeechJobTest.php
```

Focused tone backend coverage:

```bash
vendor/bin/pest tests/Unit/Services/TranslationToneCatalogTest.php \
  tests/Feature/Translation/TranslationWorkspaceLivewireTest.php \
  tests/Feature/Translation/StartTranslationWorkflowTest.php \
  tests/Feature/Translation/TranslateAndSynthesizeSpeechJobTest.php
```

PHPStan may need extra memory on constrained hosts: `vendor/bin/phpstan analyse --memory-limit=512M`.

## Agent / contributor docs

See [AGENTS.md](AGENTS.md) for coding conventions and review checklist.
