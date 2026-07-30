<div
    class="mx-auto flex w-full max-w-3xl flex-col gap-6"
    data-translation-workspace
    @if ($this->hasInFlightTurns) wire:poll.5s="pollStatus" @endif
>
    <div
        class="flex items-center justify-end rounded-lg border border-stone-200 bg-stone-50 px-2 py-1.5"
        role="toolbar"
        aria-label="Debug controls"
    >
        <button
            type="button"
            wire:click="toggleDebugLogs"
            title="{{ $showDebugLogs ? 'Hide debug logs' : 'Show debug logs' }}"
            aria-label="{{ $showDebugLogs ? 'Hide debug logs' : 'Show debug logs' }}"
            aria-pressed="{{ $showDebugLogs ? 'true' : 'false' }}"
            @class([
                'inline-flex size-8 shrink-0 items-center justify-center rounded-md border transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-800',
                'border-teal-300 bg-teal-100 text-teal-900' => $showDebugLogs,
                'border-stone-300 bg-white text-stone-700 hover:bg-stone-100 hover:text-stone-900' => ! $showDebugLogs,
            ])
        >
            <x-lucide-icon :name="$showDebugLogs ? 'eye-off' : 'bug'" class="size-4 shrink-0 text-current" />
        </button>
    </div>

    <section
        data-translation-chat
        class="rounded-xl border border-stone-200 bg-white/80 shadow-sm"
        aria-label="Translation chat"
    >
        <div
            data-translation-scroll
            class="space-y-4 px-4 py-5 sm:px-5"
            aria-live="polite"
        >
            @forelse ($turns as $turn)
                <article
                    class="space-y-3"
                    wire:key="turn-{{ $turn['id'] }}"
                    data-turn-id="{{ $turn['id'] }}"
                    @if (! empty($turn['stream_url']))
                        data-turn-stream="{{ $turn['stream_url'] }}"
                    @endif
                >
                    <div class="flex items-end justify-end gap-2">
                        <div class="max-w-[85%] rounded-2xl rounded-br-md bg-teal-800 px-4 py-3 text-sm leading-relaxed text-white whitespace-pre-wrap">
                            {{ $turn['source_text'] }}
                        </div>
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-teal-800 text-white"
                            aria-hidden="true"
                        >
                            <x-lucide-icon name="user" class="size-4 shrink-0" />
                        </div>
                    </div>

                    <div class="flex items-end justify-start gap-2">
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-full border border-stone-200 bg-stone-100 text-stone-700"
                            aria-hidden="true"
                        >
                            <x-lucide-icon name="languages" class="size-4 shrink-0" />
                        </div>
                        <div class="max-w-[85%] space-y-3 rounded-2xl rounded-bl-md border border-stone-200 bg-stone-50 px-4 py-3 text-sm leading-relaxed text-stone-900">
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        data-turn-status="{{ $turn['status'] }}"
                                        @class([
                                            'rounded-md px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide',
                                            'bg-amber-100 text-amber-900' => in_array($turn['status'], ['queued', 'translating', 'synthesizing'], true),
                                            'bg-teal-100 text-teal-900' => $turn['status'] === 'completed',
                                            'bg-red-100 text-red-800' => $turn['status'] === 'failed',
                                        ])
                                    >
                                        {{ $turn['status'] }}
                                    </span>
                                    <span
                                        data-turn-target-language="{{ $turn['target_language'] ?? 'ja' }}"
                                        class="rounded-md border border-stone-200 bg-white px-2 py-0.5 text-[10px] font-medium tracking-wide text-stone-700"
                                    >
                                        {{ $turn['target_language_label'] ?? strtoupper((string) ($turn['target_language'] ?? 'ja')) }}
                                    </span>
                                </div>

                                <button
                                    type="button"
                                    wire:click="selectDebugTurn('{{ $turn['id'] }}')"
                                    class="text-[11px] font-medium text-stone-500 hover:text-teal-800"
                                >
                                    Debug
                                </button>
                            </div>

                            @if (in_array($turn['status'], ['queued', 'translating', 'synthesizing'], true))
                                <p class="text-stone-600" data-turn-status-label>
                                    @switch($turn['status'])
                                        @case('queued')
                                            Queued — waiting for a worker…
                                            @break
                                        @case('translating')
                                            Translating to {{ $turn['target_language_label'] ?? 'target language' }}…
                                            @break
                                        @case('synthesizing')
                                            Synthesizing speech…
                                            @break
                                    @endswitch
                                </p>

                                <p
                                    data-turn-writing
                                    @class([
                                        'translation-writing inline-flex min-h-5 items-center gap-2 text-stone-500',
                                        'hidden' => filled($turn['translation']),
                                    ])
                                    aria-hidden="true"
                                >
                                    <span class="translation-writing-dots" aria-hidden="true">
                                        <span></span><span></span><span></span>
                                    </span>
                                    <span class="translation-writing-caret" aria-hidden="true"></span>
                                </p>

                                <p
                                    data-turn-translation
                                    @class([
                                        'translation-text whitespace-pre-wrap text-stone-900',
                                        'hidden' => blank($turn['translation']),
                                    ])
                                >{{ $turn['translation'] }}</p>
                            @elseif ($turn['status'] === 'failed')
                                <p class="font-medium text-red-800" role="alert" data-turn-error>
                                    {{ $turn['error'] ?: 'Translation failed. Please try again.' }}
                                </p>
                            @else
                                <p class="translation-text whitespace-pre-wrap" data-turn-translation>{{ $turn['translation'] }}</p>

                                @if ($turn['audio_url'])
                                    <div class="space-y-2">
                                        <div
                                            data-playback-shell="{{ $turn['id'] }}"
                                            data-audio-src="{{ $turn['audio_url'] }}"
                                            data-turn-audio="{{ $turn['id'] }}"
                                            data-playback-state="idle"
                                            data-playback-enabled="false"
                                            class="hidden"
                                        >
                                            <button
                                                type="button"
                                                data-playback-toggle="{{ $turn['id'] }}"
                                                disabled
                                                title="Play speech"
                                                aria-label="Play speech"
                                                class="inline-flex items-center gap-2 rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm font-medium text-stone-800 shadow-sm transition hover:bg-stone-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-800 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                <span data-playback-icon-play class="inline-flex items-center gap-2">
                                                    <x-lucide-icon name="play" class="size-4 shrink-0" aria-hidden="true" />
                                                    Play
                                                </span>
                                                <span data-playback-icon-pause class="hidden inline-flex items-center gap-2">
                                                    <x-lucide-icon name="pause" class="size-4 shrink-0" aria-hidden="true" />
                                                    Pause
                                                </span>
                                            </button>
                                        </div>
                                        <p
                                            data-autoplay-blocked="{{ $turn['id'] }}"
                                            class="hidden text-xs text-amber-800"
                                        >
                                            Autoplay was blocked.
                                            <button
                                                type="button"
                                                data-resume-autoplay="{{ $turn['id'] }}"
                                                class="font-semibold underline"
                                            >
                                                Play now
                                            </button>
                                        </p>
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="flex h-64 items-center justify-center">
                    <p class="text-sm text-stone-500">
                        Start the conversation by typing below and choosing a target language.
                    </p>
                </div>
            @endforelse
        </div>

        <form wire:submit="submit" class="shrink-0 border-t border-stone-200 bg-white/90 p-4 sm:p-5">
            <label for="source-text" class="sr-only">Source text to translate</label>
            <textarea
                id="source-text"
                wire:model="text"
                x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.submit() }"
                rows="3"
                maxlength="10000"
                class="w-full resize-y rounded-lg border border-stone-300 bg-white px-4 py-3 text-base text-stone-900 shadow-sm outline-none transition focus:border-teal-700 focus:ring-2 focus:ring-teal-700/20"
                placeholder="Type text to translate…"
            ></textarea>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <span class="text-sm text-stone-500">{{ mb_strlen($text) }} / 10000</span>

                <div class="flex flex-wrap items-center gap-2">
                    <label for="target-language" class="sr-only">Target language</label>
                    <select
                        id="target-language"
                        wire:model.live="targetLanguage"
                        class="rounded-lg border border-stone-300 bg-white px-3 py-2.5 text-sm font-medium text-stone-800 shadow-sm outline-none transition focus:border-teal-700 focus:ring-2 focus:ring-teal-700/20"
                    >
                        @foreach ($this->languageOptions as $option)
                            <option value="{{ $option['code'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center rounded-lg bg-teal-800 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-800 disabled:cursor-not-allowed disabled:bg-stone-400"
                    >
                        <span wire:loading.remove wire:target="submit" class="inline-flex items-center gap-2 whitespace-nowrap">
                            <x-lucide-icon name="languages" class="size-4 shrink-0" aria-hidden="true" />
                            Translate &amp; Speak
                        </span>
                        <span wire:loading wire:target="submit">Starting…</span>
                    </button>
                </div>
            </div>

            @error('text')
                <p class="mt-2 text-sm text-red-700" role="alert">{{ $message }}</p>
            @enderror
            @error('targetLanguage')
                <p class="mt-2 text-sm text-red-700" role="alert">{{ $message }}</p>
            @enderror
        </form>
    </section>

    @if ($showDebugLogs)
        @php($debug = $this->debugTurn)
        <div class="grid gap-4 lg:grid-cols-2">
            <section
                class="rounded-xl border border-dashed border-stone-300 bg-stone-50/80 p-4 sm:p-5"
                aria-labelledby="worker-debug-heading"
            >
                <div class="flex items-center justify-between gap-3">
                    <h2 id="worker-debug-heading" class="text-sm font-semibold tracking-wide text-stone-800 uppercase">
                        Worker debug logs
                    </h2>
                    @if ($debug)
                        <span class="truncate text-xs text-stone-600">{{ $debug['id'] }}</span>
                    @endif
                </div>
                <pre
                    class="mt-3 max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-lg border border-stone-200 bg-white px-3 py-2 font-mono text-xs leading-relaxed text-stone-800"
                >@if ($debug && $debug['worker_logs']){{ $debug['worker_logs'] }}@elseif ($this->hasInFlightTurns)Waiting for worker activity…@elseWorker logs will appear here after you submit.@endif</pre>
            </section>

            <section
                class="rounded-xl border border-dashed border-amber-300 bg-amber-50/70 p-4 sm:p-5"
                aria-labelledby="stream-debug-heading"
            >
                <div class="flex items-center justify-between gap-3">
                    <h2 id="stream-debug-heading" class="text-sm font-semibold tracking-wide text-amber-950 uppercase">
                        AIProvider stream debug
                    </h2>
                    @if ($debug && $debug['stream_debug'])
                        <span class="text-xs text-amber-800">SSE chunks</span>
                    @endif
                </div>
                <pre
                    class="mt-3 max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-lg border border-amber-200 bg-white px-3 py-2 font-mono text-xs leading-relaxed text-stone-800"
                >@if ($debug && $debug['stream_debug']){{ $debug['stream_debug'] }}@elseif ($this->hasInFlightTurns)Waiting for AIProvider stream chunks…@elseStream output will appear here after you submit.@endif</pre>
            </section>
        </div>
    @endif
</div>
