import {
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
} from './translation-playback.js';
import {
    init as initAudioOutputDevice,
    subscribe as subscribeAudioOutputDevice,
    chooseOutputDevice,
    prepareOutputDevicePicker,
    selectOutputDevice,
    resetToSystemDefault,
    getState as getAudioOutputDeviceState,
    getUnsupportedReason,
    getSelectionMode,
} from './audio-output-device.js';
import { reconcileScroll } from './translation-scroll.js';
import { reconcileTyping, revealTranslation } from './translation-typing.js';

const streams = {
    sources: new Map(),
    refreshing: false,
};

let outputDeviceUiBound = false;

/**
 * Paint Output device controls from AudioOutputDevice state.
 * Safe across Livewire morph — re-queries the current DOM each call.
 *
 * @param {{ status: string, deviceId: string|null, label: string|null, notice: string|null }|undefined} snapshot
 */
function reconcileOutputDeviceUi(snapshot) {
    const state = snapshot ?? getAudioOutputDeviceState();
    const section = document.querySelector('[data-output-device-section]');
    if (!section) {
        return;
    }

    const statusEl = section.querySelector('[data-output-device-status]');
    const labelEl = section.querySelector('[data-output-device-label]');
    const chooseBtn = section.querySelector('[data-output-device-choose]');
    const resetBtn = section.querySelector('[data-output-device-reset]');
    const noticeEl = section.querySelector('[data-output-device-notice]');
    const hintEl = section.querySelector('[data-output-device-hint]');

    if (statusEl) {
        statusEl.setAttribute('data-output-device-status', state.status);
    }

    let labelText = 'System default';
    if (state.status === 'selected' && typeof state.label === 'string' && state.label !== '') {
        labelText = state.label;
    } else if (state.status === 'unsupported') {
        labelText = getUnsupportedReason()?.message ?? 'Output selection unavailable in this browser';
    }

    if (labelEl) {
        labelEl.textContent = labelText;
    }

    if (hintEl) {
        const unsupportedReason = state.status === 'unsupported' ? getUnsupportedReason() : null;
        hintEl.textContent = unsupportedReason?.hint ?? '';
        hintEl.classList.toggle('hidden', unsupportedReason === null);
    }

    const unsupported = state.status === 'unsupported';
    const selected = state.status === 'selected';

    if (chooseBtn instanceof HTMLButtonElement) {
        chooseBtn.disabled = unsupported;
        chooseBtn.setAttribute('aria-disabled', unsupported ? 'true' : 'false');
    }

    if (resetBtn instanceof HTMLButtonElement) {
        resetBtn.disabled = !selected;
        resetBtn.setAttribute('aria-disabled', selected ? 'false' : 'true');
    }

    if (noticeEl) {
        const showNotice = typeof state.notice === 'string' && state.notice !== '';
        noticeEl.textContent = showNotice ? state.notice : '';
        noticeEl.classList.toggle('hidden', !showNotice);
    }

    const pickerEl = section.querySelector('[data-output-device-picker]');
    if (pickerEl instanceof HTMLSelectElement && unsupported) {
        pickerEl.classList.add('hidden');
        pickerEl.replaceChildren();
    }
}

/**
 * @param {Element} section
 */
async function loadOutputDevicePicker(section) {
    const chooseBtn = section.querySelector('[data-output-device-choose]');
    const pickerEl = section.querySelector('[data-output-device-picker]');
    if (!(pickerEl instanceof HTMLSelectElement)) {
        return;
    }

    const originalLabel = chooseBtn instanceof HTMLButtonElement
        ? chooseBtn.textContent
        : 'Choose output device';

    if (chooseBtn instanceof HTMLButtonElement) {
        chooseBtn.disabled = true;
        chooseBtn.textContent = 'Loading speakers…';
    }

    const devices = await prepareOutputDevicePicker();

    if (chooseBtn instanceof HTMLButtonElement) {
        chooseBtn.disabled = false;
        chooseBtn.textContent = originalLabel ?? 'Choose output device';
    }

    if (devices.length === 0) {
        return;
    }

    pickerEl.replaceChildren();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Select a speaker…';
    pickerEl.append(placeholder);

    for (const device of devices) {
        const option = document.createElement('option');
        option.value = device.deviceId;
        option.textContent = device.label;
        pickerEl.append(option);
    }

    pickerEl.classList.remove('hidden');
    pickerEl.focus();
}

/**
 * Idempotent document-level click binding for Choose / Use system default.
 * Survives Livewire morph without duplicate listeners.
 */
function bindOutputDeviceUi() {
    if (outputDeviceUiBound) {
        return;
    }

    outputDeviceUiBound = true;

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        const choose = target.closest('[data-output-device-choose]');
        if (choose instanceof HTMLButtonElement) {
            if (choose.disabled) {
                return;
            }

            event.preventDefault();

            if (getSelectionMode() === 'enumerate') {
                const section = choose.closest('[data-output-device-section]');
                if (section instanceof Element) {
                    void loadOutputDevicePicker(section);
                }

                return;
            }

            void chooseOutputDevice();

            return;
        }

        const reset = target.closest('[data-output-device-reset]');
        if (reset instanceof HTMLButtonElement) {
            if (reset.disabled) {
                return;
            }

            event.preventDefault();
            resetToSystemDefault();
        }
    });

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLSelectElement) || !target.matches('[data-output-device-picker]')) {
            return;
        }

        const option = target.selectedOptions[0];
        if (!option || option.value === '') {
            return;
        }

        selectOutputDevice({
            deviceId: option.value,
            label: option.textContent ?? 'Selected device',
        });

        target.classList.add('hidden');
        target.value = '';
    });
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
                label.textContent = 'Translating…';
            } else if (status === 'synthesizing') {
                label.textContent = 'Synthesizing speech…';
            }
        }
    }

    const translationNode = article.querySelector('[data-turn-translation]');
    if (translationNode) {
        if (translation !== '') {
            revealTranslation(turnId, translationNode, translation);
        } else if (status && ['queued', 'translating', 'synthesizing'].includes(status)) {
            const writing = article.querySelector('[data-turn-writing]');
            writing?.classList.remove('hidden');
        }
    }

    if (status === 'failed') {
        const writing = article.querySelector('[data-turn-writing]');
        writing?.classList.add('hidden');
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

function reconcilePlaybackUi() {
    hydrateFromDom();
    refreshControls();
}

function reconcileUi() {
    reconcileStreams();
    reconcilePlaybackUi();
    reconcileTyping();
    reconcileScroll();
    reconcileOutputDeviceUi();
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
        reconcileUi();
    });
});

document.addEventListener('livewire:navigated', () => {
    reconcileUi();
});

document.addEventListener('DOMContentLoaded', () => {
    reconcileUi();
});

bindUi();

initAudioOutputDevice();
subscribeAudioOutputDevice(reconcileOutputDeviceUi);
bindOutputDeviceUi();
reconcileOutputDeviceUi();

window.TranslationPlayback = {
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
};

window.TranslationStreams = {
    reconcile: reconcileStreams,
    close: closeStream,
};
