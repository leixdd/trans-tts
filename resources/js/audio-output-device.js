/**
 * Browser TTS output-device preference.
 *
 * Owns capability detection, Chromium selectAudioOutput picker, localStorage
 * persistence, status publication, and HTMLMediaElement sink assignment.
 * Device identifiers stay browser-local — never send to the server.
 *
 * Status: system | selected | unsupported | fallback
 */

const STORAGE_KEY = 'tts_audio_output_device';
const FALLBACK_NOTICE = 'Selected output device is unavailable. Using system default.';

const state = {
    status: 'system',
    deviceId: null,
    label: null,
    notice: null,
};

/** @type {Set<(snapshot: ReturnType<typeof getState>) => void>} */
const listeners = new Set();

function isSupported() {
    if (typeof window === 'undefined' || !window.isSecureContext) {
        return false;
    }

    const mediaDevices = navigator?.mediaDevices;
    if (!mediaDevices || typeof mediaDevices.selectAudioOutput !== 'function') {
        return false;
    }

    if (
        typeof HTMLMediaElement === 'undefined'
        || typeof HTMLMediaElement.prototype.setSinkId !== 'function'
    ) {
        return false;
    }

    return true;
}

function getState() {
    return {
        status: state.status,
        deviceId: state.deviceId,
        label: state.label,
        notice: state.notice,
    };
}

function publish(patch) {
    if (Object.prototype.hasOwnProperty.call(patch, 'status')) {
        state.status = patch.status;
    }
    if (Object.prototype.hasOwnProperty.call(patch, 'deviceId')) {
        state.deviceId = patch.deviceId;
    }
    if (Object.prototype.hasOwnProperty.call(patch, 'label')) {
        state.label = patch.label;
    }
    if (Object.prototype.hasOwnProperty.call(patch, 'notice')) {
        state.notice = patch.notice;
    }

    const snapshot = getState();
    for (const listener of listeners) {
        try {
            listener(snapshot);
        } catch {
            // Listener errors must not break routing.
        }
    }
}

/**
 * @param {(snapshot: ReturnType<typeof getState>) => void} listener
 * @returns {() => void} unsubscribe
 */
function subscribe(listener) {
    if (typeof listener !== 'function') {
        return () => {};
    }

    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function readStoredPreference() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (typeof raw !== 'string' || raw === '') {
            return null;
        }

        const parsed = JSON.parse(raw);
        if (
            parsed
            && typeof parsed.deviceId === 'string'
            && parsed.deviceId !== ''
            && typeof parsed.label === 'string'
        ) {
            return {
                deviceId: parsed.deviceId,
                label: parsed.label,
            };
        }
    } catch {
        // Corrupt or inaccessible storage — treat as absent.
    }

    return null;
}

function writeStoredPreference(preference) {
    try {
        if (!preference) {
            localStorage.removeItem(STORAGE_KEY);

            return;
        }

        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({
                deviceId: preference.deviceId,
                label: preference.label,
            }),
        );
    } catch {
        // Quota / private mode — in-memory state still drives this session.
    }
}

function clearPreference() {
    writeStoredPreference(null);
}

/**
 * Restore durable preference (when supported) and publish initial state.
 * @returns {ReturnType<typeof getState>}
 */
function init() {
    if (!isSupported()) {
        publish({
            status: 'unsupported',
            deviceId: null,
            label: null,
            notice: null,
        });

        return getState();
    }

    const preference = readStoredPreference();
    if (preference) {
        publish({
            status: 'selected',
            deviceId: preference.deviceId,
            label: preference.label,
            notice: null,
        });
    } else {
        publish({
            status: 'system',
            deviceId: null,
            label: null,
            notice: null,
        });
    }

    return getState();
}

/**
 * User-activated Chromium output picker. Cancel / dismiss preserves prior selection.
 * @returns {Promise<ReturnType<typeof getState>>}
 */
async function chooseOutputDevice() {
    if (!isSupported()) {
        publish({
            status: 'unsupported',
            deviceId: null,
            label: null,
            notice: null,
        });

        return getState();
    }

    try {
        const device = await navigator.mediaDevices.selectAudioOutput();
        const deviceId = typeof device?.deviceId === 'string' ? device.deviceId : '';
        const label = typeof device?.label === 'string' && device.label !== ''
            ? device.label
            : 'Selected device';

        if (deviceId === '') {
            return getState();
        }

        writeStoredPreference({ deviceId, label });
        publish({
            status: 'selected',
            deviceId,
            label,
            notice: null,
        });
    } catch {
        // Picker cancel (AbortError / NotAllowedError) and other picker failures
        // must preserve the prior durable selection without an error notice.
    }

    return getState();
}

/**
 * Clear durable preference and return to OS/browser default output.
 * @returns {ReturnType<typeof getState>}
 */
function resetToSystemDefault() {
    clearPreference();

    if (!isSupported()) {
        publish({
            status: 'unsupported',
            deviceId: null,
            label: null,
            notice: null,
        });

        return getState();
    }

    publish({
        status: 'system',
        deviceId: null,
        label: null,
        notice: null,
    });

    return getState();
}

/**
 * Assign the preferred sink before playback begins.
 * On failure: clear preference, publish fallback notice, resolve so play continues
 * on the system default. Does not touch any already-playing element elsewhere.
 *
 * @param {HTMLMediaElement} audioElement
 * @returns {Promise<void>}
 */
async function applySink(audioElement) {
    if (
        !audioElement
        || typeof audioElement.setSinkId !== 'function'
        || state.status !== 'selected'
        || typeof state.deviceId !== 'string'
        || state.deviceId === ''
    ) {
        return;
    }

    try {
        await audioElement.setSinkId(state.deviceId);
    } catch {
        clearPreference();
        publish({
            status: 'fallback',
            deviceId: null,
            label: null,
            notice: FALLBACK_NOTICE,
        });
    }
}

export {
    STORAGE_KEY,
    FALLBACK_NOTICE,
    isSupported,
    init,
    getState,
    subscribe,
    chooseOutputDevice,
    resetToSystemDefault,
    applySink,
};
