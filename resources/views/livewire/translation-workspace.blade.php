<div
    class="mx-auto flex w-full max-w-6xl flex-col gap-8"
    @if ($this->isInFlight) wire:poll.2s="pollStatus" @endif
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
                Enter English on the left. Japanese translation and speech appear on the right when ready.
            </p>
        </div>
    </header>

    <div class="grid gap-6 lg:grid-cols-2 lg:gap-8">
        <section class="flex min-h-[28rem] flex-col gap-4" aria-labelledby="source-heading">
            <div class="flex items-center justify-between gap-3">
                <h2 id="source-heading" class="text-lg font-semibold text-stone-900">
                    English
                </h2>
                <span class="text-sm text-stone-500">
                    {{ mb_strlen($text) }} / 10000
                </span>
            </div>

            <form wire:submit="submit" class="flex flex-1 flex-col gap-4">
                <label for="source-text" class="sr-only">English source text</label>
                <textarea
                    id="source-text"
                    wire:model="text"
                    rows="14"
                    maxlength="10000"
                    @disabled($this->isInFlight)
                    class="min-h-64 w-full flex-1 resize-y rounded-lg border border-stone-300 bg-white px-4 py-3 text-base text-stone-900 shadow-sm outline-none transition focus:border-teal-700 focus:ring-2 focus:ring-teal-700/20 disabled:cursor-not-allowed disabled:bg-stone-100 disabled:text-stone-500"
                    placeholder="Type or paste English text to translate…"
                ></textarea>

                @error('text')
                    <p class="text-sm text-red-700" role="alert">{{ $message }}</p>
                @enderror

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    @disabled($this->isInFlight)
                    class="inline-flex items-center justify-center rounded-lg bg-teal-800 px-5 py-3 text-sm font-semibold text-white transition hover:bg-teal-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-800 disabled:cursor-not-allowed disabled:bg-stone-400"
                >
                    <span wire:loading.remove wire:target="submit">
                        @if ($status === 'failed')
                            Retry Translate &amp; Speak
                        @else
                            Translate &amp; Speak
                        @endif
                    </span>
                    <span wire:loading wire:target="submit">Starting…</span>
                </button>
            </form>
        </section>

        <section
            class="flex min-h-[28rem] flex-col gap-4 rounded-xl border border-stone-200 bg-white/80 p-5 shadow-sm sm:p-6"
            aria-labelledby="result-heading"
            aria-live="polite"
        >
            <div class="flex items-center justify-between gap-3">
                <h2 id="result-heading" class="text-lg font-semibold text-stone-900">
                    Japanese
                </h2>

                @if ($status)
                    <span
                        @class([
                            'rounded-md px-2.5 py-1 text-xs font-medium uppercase tracking-wide',
                            'bg-amber-100 text-amber-900' => $this->isInFlight,
                            'bg-teal-100 text-teal-900' => $status === 'completed',
                            'bg-red-100 text-red-800' => $status === 'failed',
                        ])
                    >
                        {{ $status }}
                    </span>
                @endif
            </div>

            @if ($this->isInFlight)
                <div class="flex flex-1 flex-col items-start justify-center gap-3 text-stone-600">
                    <div class="h-2 w-40 overflow-hidden rounded-full bg-stone-200">
                        <div class="h-full w-1/2 animate-pulse rounded-full bg-teal-700"></div>
                    </div>
                    <p class="text-sm">
                        @switch($status)
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
                </div>
            @elseif ($status === 'failed')
                <div class="flex flex-1 flex-col justify-center gap-3" role="alert">
                    <p class="text-sm font-medium text-red-800">Translation failed</p>
                    <p class="text-sm text-stone-700">
                        {{ $error ?: 'Something went wrong. Your English text is still available — try again.' }}
                    </p>
                </div>
            @elseif ($status === 'completed')
                <div class="flex flex-1 flex-col gap-5">
                    <div class="min-h-40 flex-1 whitespace-pre-wrap rounded-lg border border-stone-200 bg-stone-50 px-4 py-3 text-base leading-relaxed text-stone-900">
                        {{ $translation }}
                    </div>

                    @if ($audioUrl)
                        <div class="flex flex-col gap-2">
                            <p class="text-sm font-medium text-stone-700">Audio</p>
                            <audio controls class="w-full" src="{{ $audioUrl }}">
                                Your browser does not support the audio element.
                            </audio>
                        </div>
                    @endif
                </div>
            @else
                <div class="flex flex-1 items-center justify-center">
                    <p class="text-sm text-stone-500">
                        Translation and audio will appear here after you submit.
                    </p>
                </div>
            @endif
        </section>
    </div>
</div>
