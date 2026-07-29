# Graph Report - .  (2026-07-30)

## Corpus Check
- Corpus is ~22,618 words - fits in a single context window. You may not need a graph.

## Summary
- 375 nodes · 596 edges · 35 communities (30 shown, 5 thin omitted)
- Extraction: 99% EXTRACTED · 1% INFERRED · 0% AMBIGUOUS · INFERRED: 8 edges (avg confidence: 0.69)
- Token cost: 10,025 input · 1,403 output

## Community Hubs (Navigation)
- TTS Job Pipeline
- Composer PHP Deps
- Browser Playback SSE
- Turn Store Prune
- Composer Scripts
- HTTP Stream Controllers
- Frontend Package Deps
- Start Translation Flow
- Auth User Models
- Typing Reveal UI
- App Service Provider
- Project Docs Branding
- Workspace Blade UI
- Repositories Guidance
- Docker Compose
- Apple Touch Icon

## God Nodes (most connected - your core abstractions)
1. `TranslationWorkflowStore` - 44 edges
2. `AnonymousVisitor` - 18 edges
3. `TranslationTurn` - 14 edges
4. `refreshControls()` - 14 edges
5. `scripts` - 13 edges
6. `TranslateAndSynthesizeSpeech` - 11 edges
7. `TranslationWorkspace` - 11 edges
8. `TranslationTurnStreamService` - 11 edges
9. `require-dev` - 11 edges
10. `revealTranslation()` - 10 edges

## Surprising Connections (you probably didn't know these)
- `StartTranslationWorkflow` --references--> `TranslationWorkflowStore`  [EXTRACTED]
  app/Actions/StartTranslationWorkflow.php → app/Services/TranslationWorkflowStore.php
- `TranslationTurnStreamService` --references--> `TranslationWorkflowStore`  [EXTRACTED]
  app/Services/TranslationTurnStreamService.php → app/Services/TranslationWorkflowStore.php
- `applySnapshot()` --calls--> `revealTranslation()`  [EXTRACTED]
  resources/js/app.js → resources/js/translation-typing.js
- `reconcileUi()` --calls--> `reconcileTyping()`  [EXTRACTED]
  resources/js/app.js → resources/js/translation-typing.js
- `TranslationAudioController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/TranslationAudioController.php → app/Http/Controllers/Controller.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Dockerized Application Services** — compose_yaml, readme, agents [EXTRACTED 0.90]

## Communities (35 total, 5 thin omitted)

### Community 0 - "TTS Job Pipeline"
Cohesion: 0.07
Nodes (14): TranslateAndSynthesizeSpeech, AIProviderSpeechService, AIProviderTranslationService, Illuminate\Contracts\Queue\ShouldBeUnique, Illuminate\Contracts\Queue\ShouldQueue, Illuminate\Filesystem\FilesystemAdapter, Illuminate\Foundation\Queue\Queueable, Illuminate\Foundation\Testing\TestCase (+6 more)

### Community 1 - "Composer PHP Deps"
Cohesion: 0.04
Nodes (46): pestphp/pest-plugin, php-http/discovery, autoload, autoload-dev, psr-4, psr-4, config, allow-plugins (+38 more)

### Community 2 - "Browser Playback SSE"
Cohesion: 0.14
Nodes (32): applySnapshot(), closeStream(), openStream(), reconcilePlaybackUi(), reconcileStreams(), reconcileUi(), refreshWorkspace(), streams (+24 more)

### Community 3 - "Turn Store Prune"
Cohesion: 0.13
Nodes (6): PruneTranslationWorkflows, TranslationTurn, TranslationWorkflowStore, Illuminate\Console\Command, Illuminate\Database\Eloquent\Concerns\HasUuids, Illuminate\Database\Eloquent\Model

### Community 4 - "Composer Scripts"
Cohesion: 0.06
Nodes (36): scripts, ci:check, dev, lint, lint:check, post-autoload-dump, post-create-project-cmd, post-root-package-install (+28 more)

### Community 5 - "HTTP Stream Controllers"
Cohesion: 0.11
Nodes (11): Controller, TranslationAudioController, TranslationTurnStreamController, EnsureAnonymousVisitor, AnonymousVisitor, TranslationTurnStreamService, Closure, Illuminate\Http\Request (+3 more)

### Community 6 - "Frontend Package Deps"
Cohesion: 0.07
Nodes (27): concurrently, laravel-vite-plugin, lightningcss-linux-x64-gnu, lucide-static, dependencies, concurrently, laravel-vite-plugin, lucide-static (+19 more)

### Community 7 - "Start Translation Flow"
Cohesion: 0.12
Nodes (6): StartTranslationWorkflow, StartTranslationRequest, TranslationWorkspace, Illuminate\Contracts\View\View, Illuminate\Foundation\Http\FormRequest, Livewire\Component

### Community 8 - "Auth User Models"
Cohesion: 0.15
Nodes (10): User, UserFactory, DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Seeder, Illuminate\Foundation\Auth\User (+2 more)

### Community 9 - "Typing Reveal UI"
Cohesion: 0.25
Nodes (14): clearSession(), graphemes(), paint(), reconcileTyping(), resetTyping(), revealTranslation(), sessions, setWritingVisible() (+6 more)

### Community 11 - "Project Docs Branding"
Cohesion: 0.40
Nodes (3): CI Test Workflow, Laravel Logo Favicon, translation_turns Table

## Knowledge Gaps
- **84 isolated node(s):** `$schema`, `name`, `type`, `description`, `laravel` (+79 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **5 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `TranslationWorkflowStore` connect `Turn Store Prune` to `TTS Job Pipeline`, `HTTP Stream Controllers`, `Start Translation Flow`?**
  _High betweenness centrality (0.082) - this node is a cross-community bridge._
- **Why does `scripts` connect `Composer Scripts` to `Composer PHP Deps`?**
  _High betweenness centrality (0.031) - this node is a cross-community bridge._
- **Why does `AnonymousVisitor` connect `HTTP Stream Controllers` to `TTS Job Pipeline`, `Start Translation Flow`?**
  _High betweenness centrality (0.025) - this node is a cross-community bridge._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _84 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `TTS Job Pipeline` be split into smaller, more focused modules?**
  _Cohesion score 0.06938020351526364 - nodes in this community are weakly interconnected._
- **Should `Composer PHP Deps` be split into smaller, more focused modules?**
  _Cohesion score 0.0425531914893617 - nodes in this community are weakly interconnected._
- **Should `Browser Playback SSE` be split into smaller, more focused modules?**
  _Cohesion score 0.13902439024390245 - nodes in this community are weakly interconnected._