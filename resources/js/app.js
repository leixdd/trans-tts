const playback = {
    order: [],
    ready: new Map(),
    failed: new Set(),
    played: new Set(),
    playing: false,
    blocked: false,
    audio: null,
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
