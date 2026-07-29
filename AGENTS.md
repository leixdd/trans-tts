# AGENTS.md

Guidance for AI agents working in this repository.

## Project

- **App:** Public English→Japanese translation page with Fish Audio TTS via Novita (queued workflow; see [README.md](README.md) for setup).
- **Stack:** Laravel 13, Livewire 4, Octane (FrankenPHP), Pest, Pint, Larastan (level 7)
- **Layout:** Standard Laravel (not domain-driven). Keep new code in the standard folders.
- **Package manager (JS):** Use **bun** only. Never use npm or nodejs.
- **Runtime (local/Docker):** Octane web + `queue:work` + `schedule:work` (hourly `translations:prune`).

## Before Changing Code

1. Detect the layer you are touching (routes, controller, Livewire, model, repository, job, test).
2. Follow `.cursor/rules/` — especially naming, banned patterns, and structure.
3. Prefer the smallest change that matches existing patterns in nearby files.

## Coding Rules (non-negotiable)

- Thin controllers / Livewire; business logic in Actions, Services, or Jobs
- Form Requests + `$fillable` / `validated()` — no mass-assignment of `$request->all()`
- No `env()` outside `config/`; use `config()`
- No Eloquent/DB queries in Blade
- Raw SQL and complex Query Builder only in `app/Repositories/` (interface + Eloquent impl, DI-bound)
- All SQL values bound; dynamic identifiers whitelisted
- API responses via Resources (not raw models); paginate list endpoints
- Mutating endpoints must authorize (policy/gate/middleware)
- Queued jobs: idempotent or unique; configure `tries` / `failed()` for critical work
- Casts via `casts()` method

## Naming (quick)

| Thing | Style |
|-------|--------|
| Model / Controller | Singular (`BlogPost`, `BlogPostController`) |
| Table / route URL | Plural snake / kebab (`blog_posts`, `/blog-posts`) |
| Route names | `blog_posts.index` |
| Requests / Policies | `StoreBlogPostRequest`, `BlogPostPolicy` |
| Repos | `BlogPostRepositoryInterface`, `EloquentBlogPostRepository` |

## Commands

```bash
composer lint          # Pint fix
composer lint:check    # Pint dry-run
composer types:check   # PHPStan / Larastan
composer test          # lint check + types + Pest
php artisan test       # Pest only
bun install            # JS deps (never npm)
bun run build          # Vite build
```

## Review / PR Checklist

When reviewing or finishing a change:

- [ ] Files in the correct folders; no logic in route files
- [ ] Naming matches conventions
- [ ] No banned patterns (SQL injection, `env()`, queries in Blade, fat controllers)
- [ ] Authorization + validation on writes
- [ ] Eager-load to avoid N+1; no unbounded `Model::all()` in HTTP
- [ ] Repository SQL uses bindings + whitelists
- [ ] Feature tests cover new behavior
- [ ] `composer lint:check` / `composer test` pass when relevant

### Severity labels for review feedback

- **Critical** — security, data loss, broken behavior (block merge)
- **Suggestion** — performance, maintainability, convention
- **Nice to have** — polish

## Do Not

- Introduce DDD (`app/Domain/`) without an explicit project decision
- Add npm/nodejs workflows
- Commit, push, or open PRs unless the user asks
- Add drive-by refactors unrelated to the task
