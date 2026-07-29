<div
    class="mx-auto flex w-full max-w-3xl flex-col gap-6"
    data-translation-workspace
    @if ($this->hasInFlightTurns) wire:poll.5s="pollStatus" @endif
>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-medium tracking-wide text-teal-800 uppercase">
                EN → JA
            </p>
            <h1 class="mt-1 text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">
                Translate &amp; Speak
            </h1>
            <p class="mt-2 max-w-xl text-stone-600">
                Chat-style English to Japanese translation with speech playback history.
            </p>
        </div>
    </header>

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
        class="flex min-h-[28rem] flex-col rounded-xl border border-stone-200 bg-white/80 shadow-sm"
        aria-label="Translation chat"
    >
        <div class="flex-1 space-y-4 overflow-y-auto px-4 py-5 sm:px-5" aria-live="polite">
            @forelse ($turns as $turn)
                <article
                    class="space-y-3"
                    wire:key="turn-{{ $turn['id'] }}"
                    data-turn-id="{{ $turn['id'] }}"
                    @if (! empty($turn['stream_url']))
                        data-turn-stream="{{ $turn['stream_url'] }}"
                    @endif
                >
                    <div class="flex justify-end">
                        <div class="max-w-[85%] rounded-2xl rounded-br-md bg-teal-800 px-4 py-3 text-sm leading-relaxed text-white whitespace-pre-wrap">
                            {{ $turn['source_text'] }}
                        </div>
                    </div>

                    <div class="flex justify-start">
                        <div class="max-w-[85%] space-y-3 rounded-2xl rounded-bl-md border border-stone-200 bg-stone-50 px-4 py-3 text-sm leading-relaxed text-stone-900">
                            <div class="flex items-center justify-between gap-3">
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
                                            Translating English to Japanese…
                                            @break
                                        @case('synthesizing')
                                            Synthesizing Japanese speech…
                                            @break
                                    @endswitch
                                </p>

                                <p
                                    data-turn-translation
                                    @class([
                                        'whitespace-pre-wrap text-stone-900',
                                        'hidden' => blank($turn['translation']),
                                    ])
                                >{{ $turn['translation'] }}</p>
                            @elseif ($turn['status'] === 'failed')
                                <p class="font-medium text-red-800" role="alert" data-turn-error>
                                    {{ $turn['error'] ?: 'Translation failed. Please try again.' }}
                                </p>
                            @else
                                <p class="whitespace-pre-wrap" data-turn-translation>{{ $turn['translation'] }}</p>

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
                        Start the conversation by translating English below.
                    </p>
                </div>
            @endforelse
        </div>

        <form wire:submit="submit" class="border-t border-stone-200 bg-white/90 p-4 sm:p-5">
            <label for="source-text" class="sr-only">English source text</label>
            <textarea
                id="source-text"
                wire:model="text"
                rows="3"
                maxlength="10000"
                class="w-full resize-y rounded-lg border border-stone-300 bg-white px-4 py-3 text-base text-stone-900 shadow-sm outline-none transition focus:border-teal-700 focus:ring-2 focus:ring-teal-700/20"
                placeholder="Type English to translate…"
            ></textarea>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <span class="text-sm text-stone-500">{{ mb_strlen($text) }} / 10000</span>

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

            @error('text')
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
                        Novita stream debug
                    </h2>
                    @if ($debug && $debug['stream_debug'])
                        <span class="text-xs text-amber-800">SSE chunks</span>
                    @endif
                </div>
                <pre
                    class="mt-3 max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-lg border border-amber-200 bg-white px-3 py-2 font-mono text-xs leading-relaxed text-stone-800"
                >@if ($debug && $debug['stream_debug']){{ $debug['stream_debug'] }}@elseif ($this->hasInFlightTurns)Waiting for Novita stream chunks…@elseStream output will appear here after you submit.@endif</pre>
            </section>
        </div>
    @endif
</div>
