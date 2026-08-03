/**
 * Browser TTS output-device preference.
 *
 * Owns capability detection, output picker (native or enumerateDevices fallback),
 * localStorage persistence, status publication, and HTMLMediaElement sink assignment.
 * Device identifiers stay browser-local — never send to the server.
 *
 * Status: system | selected | unsupported | fallback
 */

const STORAGE_KEY = 'tts_audio_output_device';
const FALLBACK_NOTICE = 'Selected output device is unavailable. Using system default.';
const PERMISSION_NOTICE = 'Allow microphone access once so this browser can list available speakers.';

const state = {
    status: 'system',
    deviceId: null,
    label: null,
    notice: null,
};

/** @type {Set<(snapshot: ReturnType<typeof getState>) => void>} */
const listeners = new Set();

function isSupported() {
    return getUnsupportedReason() === null;
}

/**
 * @returns {'native' | 'enumerate' | null}
 */
function getSelectionMode() {
    if (!isSupported()) {
        return null;
    }

    if (typeof navigator.mediaDevices?.selectAudioOutput === 'function') {
        return 'native';
    }

    return 'enumerate';
}

/**
 * When output selection is unavailable, returns a stable reason code and user-facing hint.
 * @returns {{ code: 'insecure-context' | 'browser-unsupported' | 'api-missing', message: string, hint: string } | null}
 */
function getUnsupportedReason() {
    if (typeof window === 'undefined' || !window.isSecureContext) {
        return {
            code: 'insecure-context',
            message: 'Output selection unavailable in this browser',
            hint: 'Open this site via https:// or http://127.0.0.1 — plain HTTP on a LAN IP or hostname is not allowed.',
        };
    }

    if (
        typeof HTMLMediaElement === 'undefined'
        || typeof HTMLMediaElement.prototype.setSinkId !== 'function'
    ) {
        return {
            code: 'api-missing',
            message: 'Output selection unavailable in this browser',
            hint: 'Use Chrome, Edge, Brave, or Firefox 116+ on HTTPS or localhost.',
        };
    }

    const mediaDevices = navigator?.mediaDevices;
    if (!mediaDevices || typeof mediaDevices.enumerateDevices !== 'function') {
        return {
            code: 'browser-unsupported',
            message: 'Output selection unavailable in this browser',
            hint: 'This browser cannot list audio output devices.',
        };
    }

    return null;
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

async function outputDevicesHaveLabels() {
    const devices = await navigator.mediaDevices.enumerateDevices();

    return devices.some(
        (device) => device.kind === 'audiooutput' && device.deviceId !== '' && device.label !== '',
    );
}

/**
 * Chromium unlocks audiooutput labels after a one-time getUserMedia grant.
 * @returns {Promise<void>}
 */
async function unlockOutputDeviceEnumeration() {
    if (await outputDevicesHaveLabels()) {
        return;
    }

    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    for (const track of stream.getTracks()) {
        track.stop();
    }
}

/**
 * @returns {Promise<Array<{ deviceId: string, label: string }>>}
 */
async function listOutputDevices() {
    if (getSelectionMode() === 'native') {
        return [];
    }

    await unlockOutputDeviceEnumeration();

    const devices = await navigator.mediaDevices.enumerateDevices();
    /** @type {Array<{ deviceId: string, label: string }>} */
    const outputs = [];
    const seen = new Set();

    for (const device of devices) {
        if (device.kind !== 'audiooutput' || device.deviceId === '' || seen.has(device.deviceId)) {
            continue;
        }

        seen.add(device.deviceId);
        outputs.push({
            deviceId: device.deviceId,
            label: device.label !== '' ? device.label : 'Speaker',
        });
    }

    return outputs;
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
 * @param {{ deviceId: string, label: string }} preference
 * @returns {ReturnType<typeof getState>}
 */
function selectOutputDevice(preference) {
    if (
        typeof preference?.deviceId !== 'string'
        || preference.deviceId === ''
        || typeof preference?.label !== 'string'
    ) {
        return getState();
    }

    writeStoredPreference(preference);
    publish({
        status: 'selected',
        deviceId: preference.deviceId,
        label: preference.label,
        notice: null,
    });

    return getState();
}

/**
 * Chromium fallback: list outputs after optional mic permission unlock.
 * @returns {Promise<Array<{ deviceId: string, label: string }>>}
 */
async function prepareOutputDevicePicker() {
    if (!isSupported() || getSelectionMode() !== 'enumerate') {
        return [];
    }

    try {
        const devices = await listOutputDevices();
        publish({ notice: null });

        return devices;
    } catch {
        publish({ notice: PERMISSION_NOTICE });

        return [];
    }
}

/**
 * User-activated native output picker (Firefox). Cancel preserves prior selection.
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

    if (getSelectionMode() !== 'native') {
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

        return selectOutputDevice({ deviceId, label });
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
    PERMISSION_NOTICE,
    isSupported,
    getUnsupportedReason,
    getSelectionMode,
    init,
    getState,
    subscribe,
    chooseOutputDevice,
    prepareOutputDevicePicker,
    selectOutputDevice,
    resetToSystemDefault,
    applySink,
};
