import { afterEach, beforeEach, describe, expect, test } from 'bun:test';
import {
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
} from './audio-output-device.js';

/** @type {Storage|null} */
let storageBacking = null;

/** @type {ReturnType<typeof installSupportedEnvironment>|null} */
let env = null;

function installSupportedEnvironment() {
    storageBacking = new Map();

    const localStorageMock = {
        /** @param {string} key */
        getItem(key) {
            return storageBacking?.get(key) ?? null;
        },
        /** @param {string} key @param {string} value */
        setItem(key, value) {
            storageBacking?.set(key, value);
        },
        /** @param {string} key */
        removeItem(key) {
            storageBacking?.delete(key);
        },
        clear() {
            storageBacking?.clear();
        },
    };

    Object.defineProperty(globalThis, 'localStorage', {
        configurable: true,
        value: localStorageMock,
    });

    Object.defineProperty(globalThis, 'window', {
        configurable: true,
        value: { isSecureContext: true },
    });

    /** @type {() => Promise<{ deviceId: string, label: string }>} */
    let selectBehavior = async () => ({ deviceId: 'device-a', label: 'Mock Headphones' });

    /** @type {Array<{ deviceId: string, kind: string, label: string }>} */
    let enumeratedDevices = [
        { deviceId: 'device-a', kind: 'audiooutput', label: 'Mock Headphones' },
        { deviceId: 'device-b', kind: 'audiooutput', label: 'Mock Speakers' },
    ];

    const mediaDevices = {
        /** @param {() => Promise<{ deviceId: string, label: string }>} fn */
        setSelectBehavior(fn) {
            selectBehavior = fn;
        },
        /** @param {Array<{ deviceId: string, kind: string, label: string }>} devices */
        setEnumeratedDevices(devices) {
            enumeratedDevices = devices;
        },
        selectAudioOutput: async () => selectBehavior(),
        enumerateDevices: async () => structuredClone(enumeratedDevices),
        getUserMedia: async () => ({
            getTracks: () => [{ stop: () => {} }],
        }),
    };

    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: { mediaDevices },
    });

    /** @type {'resolve' | 'reject'} */
    let sinkBehavior = 'resolve';

    class MockMediaElement {
        /** @type {string|null} */
        sinkId = null;

        /** @param {string} deviceId */
        async setSinkId(deviceId) {
            if (sinkBehavior === 'reject') {
                throw new Error('sink unavailable');
            }
            this.sinkId = deviceId;
        }
    }

    Object.defineProperty(globalThis, 'HTMLMediaElement', {
        configurable: true,
        value: MockMediaElement,
    });
    MockMediaElement.prototype.setSinkId = MockMediaElement.prototype.setSinkId;

    return {
        mediaDevices,
        /** @param {'resolve' | 'reject'} behavior */
        setSinkBehavior(behavior) {
            sinkBehavior = behavior;
        },
        createElement() {
            return new MockMediaElement();
        },
    };
}

function installUnsupportedEnvironment() {
    storageBacking = new Map();

    Object.defineProperty(globalThis, 'localStorage', {
        configurable: true,
        value: {
            getItem: () => null,
            setItem: () => {},
            removeItem: () => {},
            clear: () => {},
        },
    });

    Object.defineProperty(globalThis, 'window', {
        configurable: true,
        value: { isSecureContext: false },
    });

    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: { mediaDevices: {} },
    });

    Object.defineProperty(globalThis, 'HTMLMediaElement', {
        configurable: true,
        value: class {
            setSinkId() {
                throw new Error('unsupported');
            }
        },
    });
}

beforeEach(() => {
    env = installSupportedEnvironment();
    storageBacking?.clear();
    init();
});

afterEach(() => {
    env = null;
});

describe('AudioOutputDevice capability', () => {
    test('isSupported is true in secure context with setSinkId and enumerateDevices', () => {
        expect(isSupported()).toBe(true);
        expect(getSelectionMode()).toBe('native');
    });

    test('isSupported is true in Chromium when only setSinkId and enumerateDevices exist', () => {
        delete env?.mediaDevices.selectAudioOutput;
        expect(isSupported()).toBe(true);
        expect(getSelectionMode()).toBe('enumerate');
    });

    test('init reports unsupported when APIs are missing', () => {
        installUnsupportedEnvironment();
        const state = init();

        expect(isSupported()).toBe(false);
        expect(getUnsupportedReason()?.code).toBe('insecure-context');
        expect(state.status).toBe('unsupported');
        expect(state.deviceId).toBeNull();
    });
});

describe('AudioOutputDevice persistence', () => {
    test('chooseOutputDevice stores deviceId and label in localStorage only', async () => {
        await chooseOutputDevice();

        const raw = localStorage.getItem(STORAGE_KEY);
        expect(raw).not.toBeNull();
        const parsed = JSON.parse(String(raw));
        expect(parsed).toEqual({ deviceId: 'device-a', label: 'Mock Headphones' });
        expect(Object.keys(parsed)).toEqual(['deviceId', 'label']);

        const state = getState();
        expect(state.status).toBe('selected');
        expect(state.deviceId).toBe('device-a');
        expect(state.label).toBe('Mock Headphones');
    });

    test('init restores durable preference from localStorage', () => {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ deviceId: 'saved-id', label: 'Saved Earphones' }),
        );

        const state = init();
        expect(state.status).toBe('selected');
        expect(state.deviceId).toBe('saved-id');
        expect(state.label).toBe('Saved Earphones');
    });

    test('resetToSystemDefault clears storage and returns to system status', async () => {
        await chooseOutputDevice();
        expect(localStorage.getItem(STORAGE_KEY)).not.toBeNull();

        const state = resetToSystemDefault();
        expect(state.status).toBe('system');
        expect(state.deviceId).toBeNull();
        expect(localStorage.getItem(STORAGE_KEY)).toBeNull();
    });
});

describe('AudioOutputDevice picker behavior', () => {
    test('picker cancel preserves prior selection without notice', async () => {
        await chooseOutputDevice();
        const prior = getState();

        env?.mediaDevices.setSelectBehavior(async () => {
            throw new DOMException('User cancelled', 'AbortError');
        });

        await chooseOutputDevice();
        const after = getState();

        expect(after.status).toBe('selected');
        expect(after.deviceId).toBe(prior.deviceId);
        expect(after.label).toBe(prior.label);
        expect(after.notice).toBeNull();
    });

    test('empty deviceId from picker preserves prior selection', async () => {
        await chooseOutputDevice();
        const prior = getState();

        env?.mediaDevices.setSelectBehavior(async () => ({ deviceId: '', label: '' }));

        await chooseOutputDevice();
        expect(getState().deviceId).toBe(prior.deviceId);
    });
});

describe('AudioOutputDevice enumerate fallback', () => {
    test('prepareOutputDevicePicker lists audio outputs without native picker', async () => {
        delete env?.mediaDevices.selectAudioOutput;

        const devices = await prepareOutputDevicePicker();
        expect(devices).toEqual([
            { deviceId: 'device-a', label: 'Mock Headphones' },
            { deviceId: 'device-b', label: 'Mock Speakers' },
        ]);
    });

    test('selectOutputDevice stores enumerated choice', () => {
        const state = selectOutputDevice({ deviceId: 'device-b', label: 'Mock Speakers' });
        expect(state.status).toBe('selected');
        expect(state.deviceId).toBe('device-b');
        expect(JSON.parse(String(localStorage.getItem(STORAGE_KEY)))).toEqual({
            deviceId: 'device-b',
            label: 'Mock Speakers',
        });
    });

    test('prepareOutputDevicePicker publishes permission notice when getUserMedia fails', async () => {
        delete env?.mediaDevices.selectAudioOutput;
        env?.mediaDevices.setEnumeratedDevices([
            { deviceId: '', kind: 'audiooutput', label: '' },
        ]);
        env.mediaDevices.getUserMedia = async () => {
            throw new DOMException('denied', 'NotAllowedError');
        };

        const devices = await prepareOutputDevicePicker();
        expect(devices).toEqual([]);
        expect(getState().notice).toBe(PERMISSION_NOTICE);
    });
});

describe('AudioOutputDevice applySink', () => {
    test('applySink assigns sink when status is selected', async () => {
        await chooseOutputDevice();
        const element = env?.createElement();
        expect(element).toBeDefined();

        await applySink(/** @type {HTMLMediaElement} */ (element));

        expect(element?.sinkId).toBe('device-a');
    });

    test('applySink is no-op for system status', async () => {
        const element = env?.createElement();
        await applySink(/** @type {HTMLMediaElement} */ (element));
        expect(element?.sinkId).toBeNull();
    });

    test('sink failure clears preference and publishes fallback notice', async () => {
        await chooseOutputDevice();
        env?.setSinkBehavior('reject');

        const element = env?.createElement();
        await applySink(/** @type {HTMLMediaElement} */ (element));

        const state = getState();
        expect(state.status).toBe('fallback');
        expect(state.notice).toBe(FALLBACK_NOTICE);
        expect(localStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    test('applySink resolves after fallback so playback can continue', async () => {
        await chooseOutputDevice();
        env?.setSinkBehavior('reject');

        const element = env?.createElement();
        await expect(applySink(/** @type {HTMLMediaElement} */ (element))).resolves.toBeUndefined();
    });
});

describe('AudioOutputDevice subscribe', () => {
    test('subscribe receives state updates on choose and reset', async () => {
        /** @type {string[]} */
        const statuses = [];
        const unsubscribe = subscribe((snapshot) => {
            statuses.push(snapshot.status);
        });

        await chooseOutputDevice();
        resetToSystemDefault();
        unsubscribe();

        expect(statuses).toContain('selected');
        expect(statuses).toContain('system');
    });
});
