const playback = {
    order: [],
    ready: new Map(),
    failed: new Set(),
    played: new Set(),
    playing: false,
    blocked: false,
    audio: null,
};

const streams = {
    sources: new Map(),
    refreshing: false,
};

function syncOrder(ids) {
    if (!Array.isArray(ids)) {
        return;
    }

    const seen = new Set(playback.order);
    for (const id of ids) {
        if (typeof id === 'string' && id !== '' && !seen.has(id)) {
            playback.order.push(id);
            seen.add(id);
        }
    }

    tryPlay();
}

function markReady(id, url) {
    if (typeof id !== 'string' || typeof url !== 'string' || url === '') {
        return;
    }

    playback.ready.set(id, url);
    tryPlay();
}

function markFailed(id) {
    if (typeof id !== 'string' || id === '') {
        return;
    }

    playback.failed.add(id);
    tryPlay();
}

function tryPlay() {
    if (playback.playing || playback.blocked) {
        return;
    }

    for (const id of playback.order) {
        if (playback.played.has(id) || playback.failed.has(id)) {
            continue;
        }

        const url = playback.ready.get(id);
        if (!url) {
            return;
        }

        play(id, url);
        return;
    }
}

function play(id, url) {
    playback.playing = true;

    if (playback.audio) {
        playback.audio.pause();
        playback.audio = null;
    }

    const audio = new Audio(url);
    playback.audio = audio;

    const finish = () => {
        playback.played.add(id);
        playback.playing = false;
        playback.audio = null;
        setBlockedUi(false, id);
        tryPlay();
    };

    audio.addEventListener('ended', finish);
    audio.addEventListener('error', finish);

    const attempt = audio.play();
    if (attempt && typeof attempt.then === 'function') {
        attempt
            .then(() => {
                setBlockedUi(false, id);
            })
            .catch(() => {
                playback.playing = false;
                playback.blocked = true;
                setBlockedUi(true, id);
            });
    }
}

function setBlockedUi(blocked, turnId) {
    document.querySelectorAll('[data-autoplay-blocked]').forEach((el) => {
        el.classList.add('hidden');
    });

    if (!blocked || !turnId) {
        return;
    }

    const notice = document.querySelector(`[data-autoplay-blocked="${turnId}"]`);
    if (notice) {
        notice.classList.remove('hidden');
    }
}

function resumeFromUser(turnId) {
    playback.blocked = false;
    setBlockedUi(false, turnId);

    if (typeof turnId === 'string' && playback.ready.has(turnId) && !playback.played.has(turnId)) {
        play(turnId, playback.ready.get(turnId));
        return;
    }

    tryPlay();
}

function closeStream(id) {
    const entry = streams.sources.get(id);
    if (!entry) {
        return;
    }

    entry.source.close();
    streams.sources.delete(id);
}

function applySnapshot(turnId, snapshot) {
    const article = document.querySelector(`[data-turn-id="${turnId}"]`);
    if (!article) {
        return;
    }

    const status = typeof snapshot?.status === 'string' ? snapshot.status : null;
    const translation = typeof snapshot?.translation === 'string' ? snapshot.translation : '';
    const error = typeof snapshot?.error === 'string' ? snapshot.error : '';

    if (status) {
        const badge = article.querySelector('[data-turn-status]');
        if (badge) {
            badge.textContent = status;
            badge.dataset.turnStatus = status;
            badge.classList.toggle('bg-amber-100', ['queued', 'translating', 'synthesizing'].includes(status));
            badge.classList.toggle('text-amber-900', ['queued', 'translating', 'synthesizing'].includes(status));
            badge.classList.toggle('bg-teal-100', status === 'completed');
            badge.classList.toggle('text-teal-900', status === 'completed');
            badge.classList.toggle('bg-red-100', status === 'failed');
            badge.classList.toggle('text-red-800', status === 'failed');
        }

        const label = article.querySelector('[data-turn-status-label]');
        if (label) {
            if (status === 'queued') {
                label.textContent = 'Queued — waiting for a worker…';
            } else if (status === 'translating') {
                label.textContent = 'Translating English to Japanese…';
            } else if (status === 'synthesizing') {
                label.textContent = 'Synthesizing Japanese speech…';
            }
        }
    }

    const translationNode = article.querySelector('[data-turn-translation]');
    if (translationNode && translation !== '') {
        translationNode.textContent = translation;
        translationNode.classList.remove('hidden');
    }

    if (status === 'failed') {
        const errorNode = article.querySelector('[data-turn-error]');
        if (errorNode) {
            errorNode.textContent = error || 'Translation failed. Please try again.';
            errorNode.classList.remove('hidden');
        }
    }
}

function refreshWorkspace() {
    if (streams.refreshing) {
        return;
    }

    const root = document.querySelector('[data-translation-workspace]');
    if (!root || typeof Livewire === 'undefined' || typeof Livewire.find !== 'function') {
        return;
    }

    const componentId = root.getAttribute('wire:id');
    if (!componentId) {
        return;
    }

    const component = Livewire.find(componentId);
    if (!component || typeof component.call !== 'function') {
        return;
    }

    streams.refreshing = true;
    Promise.resolve(component.call('pollStatus'))
        .catch(() => {})
        .finally(() => {
            streams.refreshing = false;
        });
}

function openStream(turnId, url) {
    if (typeof turnId !== 'string' || typeof url !== 'string' || url === '') {
        return;
    }

    const existing = streams.sources.get(turnId);
    if (existing && existing.url === url && existing.source.readyState !== EventSource.CLOSED) {
        return;
    }

    closeStream(turnId);

    const source = new EventSource(url, { withCredentials: true });

    const handlePayload = (event) => {
        let snapshot;
        try {
            snapshot = JSON.parse(event.data);
        } catch {
            return;
        }

        if (snapshot?.id && snapshot.id !== turnId) {
            return;
        }

        applySnapshot(turnId, snapshot);

        if (event.type === 'terminal' || snapshot?.terminal === true) {
            closeStream(turnId);
            refreshWorkspace();
        }
    };

    source.addEventListener('snapshot', handlePayload);
    source.addEventListener('terminal', handlePayload);
    source.addEventListener('reconnect', () => {
        // Bounded server lifetime: EventSource reconnects automatically.
    });
    source.onerror = () => {
        if (source.readyState === EventSource.CLOSED) {
            streams.sources.delete(turnId);
        }
    };

    streams.sources.set(turnId, { source, url });
}

function reconcileStreams() {
    const active = new Map();

    document.querySelectorAll('[data-turn-stream]').forEach((el) => {
        const turnId = el.getAttribute('data-turn-id');
        const url = el.getAttribute('data-turn-stream');
        if (typeof turnId === 'string' && typeof url === 'string' && url !== '') {
            active.set(turnId, url);
        }
    });

    for (const id of streams.sources.keys()) {
        if (!active.has(id)) {
            closeStream(id);
        }
    }

    for (const [id, url] of active.entries()) {
        openStream(id, url);
    }
}

document.addEventListener('livewire:init', () => {
    Livewire.on('translation-playback-sync', (payload) => {
        const order = Array.isArray(payload) ? payload[0]?.order ?? payload.order : payload?.order;
        syncOrder(order);
    });

    Livewire.on('translation-audio-ready', (payload) => {
        const data = Array.isArray(payload) ? payload[0] : payload;
        markReady(data?.id, data?.url);
    });

    Livewire.on('translation-playback-failed', (payload) => {
        const data = Array.isArray(payload) ? payload[0] : payload;
        markFailed(data?.id);
    });

    Livewire.hook('morph.updated', () => {
        reconcileStreams();
    });
});

document.addEventListener('livewire:navigated', () => {
    reconcileStreams();
});

document.addEventListener('DOMContentLoaded', () => {
    reconcileStreams();
});

document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
        return;
    }

    const button = target.closest('[data-resume-autoplay]');
    if (!button) {
        return;
    }

    resumeFromUser(button.getAttribute('data-resume-autoplay'));
});

window.TranslationPlayback = {
    syncOrder,
    markReady,
    markFailed,
    resumeFromUser,
};

window.TranslationStreams = {
    reconcile: reconcileStreams,
    close: closeStream,
};
