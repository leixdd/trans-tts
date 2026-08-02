/**
 * FIFO autoplay coordinator.
 *
 * Presentation contract (Blade hooks):
 * - [data-playback-shell="{id}"]  — control shell; JS toggles `.hidden`
 * - [data-playback-toggle="{id}"] — play/stop button; JS toggles disabled + aria/label
 * - [data-playback-icon-play] / [data-playback-icon-stop] — icon visibility inside toggle
 * - [data-audio-src="{url}"]      — signed audio URL on the shell (server-rendered)
 * - [data-autoplay-blocked="{id}"] / [data-resume-autoplay="{id}"] — existing recovery UI
 *
 * Control eligibility:
 * - Visible when the turn has audio and every earlier turn is settled (played or failed).
 * - Disabled while another turn's clip is actively playing (manual cannot interrupt).
 * - Restored history is hydrated as settled (no autoplay); in-session ready turns autoplay in order.
 *
 * States (`data-playback-state`): idle | playing
 * Labels: "Play speech" (idle) / "Stop speech" (playing)
 * Stop settles the active turn and advances FIFO (no resumable pause).
 *
 * Output routing: every new Audio awaits applySink before play(); device changes
 * apply to the next clip only (active clip is never rerouted).
 */

import { applySink } from './audio-output-device.js';

const playback = {
    order: [],
    ready: new Map(),
    failed: new Set(),
    played: new Set(),
    autoplayEligible: new Set(),
    playing: false,
    blocked: false,
    playingId: null,
    audio: null,
};

function isSettled(id) {
    return playback.played.has(id) || playback.failed.has(id);
}

function earlierTurnsSettled(id) {
    for (const earlier of playback.order) {
        if (earlier === id) {
            return true;
        }

        if (!isSettled(earlier)) {
            return false;
        }
    }

    return true;
}

function controlVisible(id) {
    return playback.ready.has(id) && earlierTurnsSettled(id);
}

function controlEnabled(id) {
    if (!controlVisible(id)) {
        return false;
    }

    if (playback.blocked) {
        return false;
    }

    if (playback.playing && playback.playingId !== id) {
        return false;
    }

    return true;
}

function controlState(id) {
    if (playback.playingId === id && playback.playing) {
        return 'playing';
    }

    return 'idle';
}

function refreshControls() {
    document.querySelectorAll('[data-playback-shell]').forEach((shell) => {
        const id = shell.getAttribute('data-playback-shell');
        if (typeof id !== 'string' || id === '') {
            return;
        }

        const visible = controlVisible(id);
        const enabled = controlEnabled(id);
        const state = controlState(id);

        shell.classList.toggle('hidden', !visible);
        shell.setAttribute('data-playback-state', state);
        shell.setAttribute('data-playback-enabled', enabled ? 'true' : 'false');

        const toggle = shell.querySelector(`[data-playback-toggle="${id}"]`)
            ?? shell.querySelector('[data-playback-toggle]');
        if (toggle && typeof toggle === 'object' && 'disabled' in toggle) {
            toggle.disabled = !enabled;
            const label = state === 'playing' ? 'Stop speech' : 'Play speech';
            toggle.setAttribute('aria-label', label);
            toggle.setAttribute('title', label);
        }

        const playIcon = shell.querySelector('[data-playback-icon-play]');
        const stopIcon = shell.querySelector('[data-playback-icon-stop]');
        if (playIcon) {
            playIcon.classList.toggle('hidden', state === 'playing');
        }
        if (stopIcon) {
            stopIcon.classList.toggle('hidden', state !== 'playing');
        }
    });
}

function hydrateFromDom() {
    document.querySelectorAll('[data-playback-shell][data-audio-src]').forEach((shell) => {
        const id = shell.getAttribute('data-playback-shell');
        const url = shell.getAttribute('data-audio-src');

        if (typeof id !== 'string' || id === '' || typeof url !== 'string' || url === '') {
            return;
        }

        if (!playback.ready.has(id)) {
            playback.ready.set(id, url);
        }

        // Restored / server-rendered completed turns are settled — never autoplay.
        if (!playback.autoplayEligible.has(id) && !playback.failed.has(id)) {
            playback.played.add(id);
        }
    });

    refreshControls();
}

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

    hydrateFromDom();
    tryPlay();
}

function markReady(id, url) {
    if (typeof id !== 'string' || typeof url !== 'string' || url === '') {
        return;
    }

    playback.ready.set(id, url);
    playback.autoplayEligible.add(id);
    // In-session readiness must not remain permanently settled from a prior hydrate race.
    playback.played.delete(id);
    tryPlay();
    refreshControls();
}

function markFailed(id) {
    if (typeof id !== 'string' || id === '') {
        return;
    }

    playback.failed.add(id);
    playback.autoplayEligible.delete(id);
    tryPlay();
    refreshControls();
}

function tryPlay() {
    if (playback.playing || playback.blocked) {
        refreshControls();
        return;
    }

    for (const id of playback.order) {
        if (isSettled(id)) {
            continue;
        }

        if (!playback.autoplayEligible.has(id)) {
            // Waiting on an unresolved in-flight turn (or missing readiness).
            refreshControls();
            return;
        }

        const url = playback.ready.get(id);
        if (!url) {
            refreshControls();
            return;
        }

        play(id, url, { autoplay: true });
        return;
    }

    refreshControls();
}

function clearAudioListeners(audio) {
    audio.onended = null;
    audio.onerror = null;
}

async function play(id, url, { autoplay = false } = {}) {
    if (playback.audio) {
        clearAudioListeners(playback.audio);
        playback.audio.pause();
        playback.audio = null;
    }

    // Gate concurrent tryPlay / manual starts while the sink is applied.
    playback.playing = true;
    playback.playingId = id;
    playback.blocked = false;

    const audio = new Audio(url);
    playback.audio = audio;

    const finish = () => {
        if (playback.audio !== audio) {
            return;
        }

        clearAudioListeners(audio);
        playback.played.add(id);
        playback.playing = false;
        playback.playingId = null;
        playback.audio = null;
        setBlockedUi(false, id);
        refreshControls();
        tryPlay();
    };

    audio.addEventListener('ended', finish);
    audio.addEventListener('error', finish);

    refreshControls();

    // Prefer the selected sink before any audible start (no burst on system default).
    await applySink(audio);

    // Stop / reset may have discarded this element while the sink was applying.
    if (playback.audio !== audio) {
        return;
    }

    const attempt = audio.play();
    if (attempt && typeof attempt.then === 'function') {
        attempt
            .then(() => {
                setBlockedUi(false, id);
                refreshControls();
            })
            .catch(() => {
                if (playback.audio !== audio) {
                    return;
                }

                playback.playing = false;
                // Keep playingId for blocked recovery targeting when autoplay was attempted.
                if (autoplay) {
                    playback.blocked = true;
                    setBlockedUi(true, id);
                } else {
                    playback.playingId = null;
                    playback.audio = null;
                }
                refreshControls();
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

    if (
        typeof turnId === 'string'
        && playback.ready.has(turnId)
        && !playback.played.has(turnId)
        && earlierTurnsSettled(turnId)
    ) {
        play(turnId, playback.ready.get(turnId), { autoplay: true });
        return;
    }

    tryPlay();
}

/**
 * Halt and discard the active clip, settle the turn, and advance FIFO.
 * Does not retain a resumable pause position.
 */
function stopManual(id) {
    if (playback.playingId !== id || !playback.audio || !playback.playing) {
        return;
    }

    const audio = playback.audio;
    clearAudioListeners(audio);
    audio.pause();

    playback.played.add(id);
    playback.playing = false;
    playback.playingId = null;
    playback.audio = null;
    setBlockedUi(false, id);
    refreshControls();
    tryPlay();
}

function playManual(id) {
    if (!controlEnabled(id)) {
        return;
    }

    // Manual start only when nothing else is active.
    if (playback.playing) {
        return;
    }

    const url = playback.ready.get(id);
    if (!url) {
        return;
    }

    // Keep settled status so replaying history does not hide later controls or block FIFO.
    play(id, url, { autoplay: false });
}

function toggleManual(id) {
    if (typeof id !== 'string' || id === '') {
        return;
    }

    if (playback.playingId === id && playback.playing) {
        stopManual(id);
        return;
    }

    playManual(id);
}

function getState() {
    return {
        order: [...playback.order],
        ready: Object.fromEntries(playback.ready),
        failed: [...playback.failed],
        played: [...playback.played],
        autoplayEligible: [...playback.autoplayEligible],
        playing: playback.playing,
        blocked: playback.blocked,
        playingId: playback.playingId,
    };
}

function reset() {
    if (playback.audio) {
        clearAudioListeners(playback.audio);
        playback.audio.pause();
    }

    playback.order = [];
    playback.ready.clear();
    playback.failed.clear();
    playback.played.clear();
    playback.autoplayEligible.clear();
    playback.playing = false;
    playback.blocked = false;
    playback.playingId = null;
    playback.audio = null;
    setBlockedUi(false, null);
}

function bindUi() {
    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        const resume = target.closest('[data-resume-autoplay]');
        if (resume) {
            resumeFromUser(resume.getAttribute('data-resume-autoplay'));
            return;
        }

        const toggle = target.closest('[data-playback-toggle]');
        if (toggle) {
            const id = toggle.getAttribute('data-playback-toggle');
            toggleManual(id);
        }
    });
}

export {
    syncOrder,
    markReady,
    markFailed,
    resumeFromUser,
    playManual,
    toggleManual,
    hydrateFromDom,
    refreshControls,
    controlVisible,
    controlEnabled,
    controlState,
    getState,
    reset,
    bindUi,
    earlierTurnsSettled,
};
