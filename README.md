# English → Japanese Translation & TTS

Public web app that accepts English text, translates it to Japanese via Novita (`google/gemma-4-31b-it`), and synthesizes Japanese speech with Fish Audio S2 Pro (WAV). Translation and TTS run in a queued background job; the Livewire UI polls workflow status until completion or failure.

**Stack:** Laravel 13, Livewire 4, Laravel Octane (FrankenPHP), Bun + Vite + Tailwind CSS 4.

## Requirements

- PHP 8.3+
- [Composer](https://getcomposer.org/)
- [Bun](https://bun.sh/) — **never use npm or Node.js** for frontend tooling in this project
- SQLite (default) or another Laravel-supported database

## Environment

Copy `.env.example` to `.env`, generate an app key, and set Novita credentials:

```bash
cp .env.example .env
php artisan key:generate
```

### Required

| Variable | Purpose |
|----------|---------|
| `APP_KEY` | Laravel encryption key |
| `NOVITA_API_KEY` | Novita API key for translation and TTS |
| `NOVITA_FISH_REFERENCE_ID` | Server-configured Fish Audio voice reference |

### Optional (defaults in `config/services.php`)

| Variable | Default |
|----------|---------|
| `NOVITA_CHAT_BASE_URL` | `https://api.novita.ai/openai/v1` |
| `NOVITA_TTS_ENDPOINT` | `https://api.novita.ai/v3/fish-audio-s2-pro-text-to-speech` |
| `NOVITA_TRANSLATION_MODEL` | `google/gemma-4-31b-it` |
| `NOVITA_TIMEOUT` | `60` (seconds) |
| `NOVITA_RETENTION_MINUTES` | `60` |
| `NOVITA_USER_AGENT` | `tts-app/1.0` |

Octane settings (`OCTANE_SERVER`, `OCTANE_HOST`, `OCTANE_PORT`, etc.) are documented in `.env.example`.

> **Security:** Do not copy Novita API keys from other projects (e.g. CIELAI) into this repository, Docker images, fixtures, or documentation. Use project-specific credentials and rotate any keys that may have been exposed elsewhere.

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

Ensure `.env` exists with `APP_KEY`, `NOVITA_API_KEY`, and `NOVITA_FISH_REFERENCE_ID`:

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

## Transient storage

Workflow status (cache) and generated WAV files are **not** permanent history. They expire after `NOVITA_RETENTION_MINUTES` (default 60). The `translations:prune` command removes expired cache entries and private audio files; it is scheduled hourly in `routes/console.php`. Audio is served only via signed URLs tied to the owning browser session.

Manual prune:

```bash
php artisan translations:prune
```

## Testing

```bash
composer test          # lint + PHPStan + Pest
vendor/bin/pest        # feature tests only (32 tests)
composer lint:check    # Pint dry-run
composer types:check   # PHPStan (512M memory limit)
bun run build          # production asset build
```

PHPStan may need extra memory on constrained hosts: `vendor/bin/phpstan analyse --memory-limit=512M`.

## Agent / contributor docs

See [AGENTS.md](AGENTS.md) for coding conventions and review checklist.
