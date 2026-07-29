# English → Japanese Translation & TTS

Public chat-style web app that accepts English messages, translates them to Japanese via AIProvider (`google/gemma-4-31b-it`), and synthesizes Japanese speech with Fish Audio S2 Pro (WAV). Each turn is queued in the background; the Livewire UI shows an ordered history, relays progressive translation over an application SSE feed (with low-frequency polling fallback), and autoplays completed audio in submission order (FIFO).

**Stack:** Laravel 13, Livewire 4, Laravel Octane (FrankenPHP), Bun + Vite + Tailwind CSS 4.

## Requirements

- PHP 8.3+
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
| `AI_PROVIDER_FISH_REFERENCE_ID` | Server-configured Fish Audio voice reference |

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

## Chat history and audio

Turns are stored in the `translation_turns` table and keyed by an anonymous encrypted `tts_visitor` cookie (not a user account). Each visitor keeps at most `AI_PROVIDER_HISTORY_LIMIT` turns for `AI_PROVIDER_RETENTION_DAYS`. Private WAV files live under `storage/app/private/translation-audio` and are streamed only through signed URLs for the owning visitor.

Autoplay uses a browser FIFO queue ordered by submission time (`resources/js/translation-playback.js`). Parallel workers may finish later chats first; the browser buffers those clips until every earlier turn has finished playing or failed. Only one clip plays at a time.

Completed turns show a custom **Play** / **Pause** control (no native `<audio controls>` / volume UI). That control appears only when the turn’s audio is ready **and** every earlier turn is settled. While another clip is playing, other turns’ controls stay disabled so manual playback cannot jump the FIFO queue. Restored history is playable manually but does not autoplay again on page load. If the browser blocks autoplay, a **Play now** control appears for the blocked turn and resumes the same ordered queue.

The `translations:prune` command removes expired turns and orphaned audio files; it is scheduled hourly in `routes/console.php`.

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
bun test resources/js  # FIFO playback coordinator (Bun)
bun run build          # production asset build
```

PHPStan may need extra memory on constrained hosts: `vendor/bin/phpstan analyse --memory-limit=512M`.

## Agent / contributor docs

See [AGENTS.md](AGENTS.md) for coding conventions and review checklist.
