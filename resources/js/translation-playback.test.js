import { beforeEach, describe, expect, test } from 'bun:test';
import {
    syncOrder,
    markReady,
    markFailed,
    resumeFromUser,
    playManual,
    toggleManual,
    controlVisible,
    controlEnabled,
    controlState,
    getState,
    reset,
    refreshControls,
    hydrateFromDom,
} from './translation-playback.js';

class MockAudio {
    /** @type {MockAudio[]} */
    static instances = [];

    /** @param {string} url */
    constructor(url) {
        this.url = url;
        this.paused = true;
        /** @type {Record<string, Function[]>} */
        this.listeners = {};
        /** @type {'resolve' | 'reject'} */
        this.playBehavior = MockAudio.nextPlayBehavior;
        MockAudio.instances.push(this);
    }

    /** @type {'resolve' | 'reject'} */
    static nextPlayBehavior = 'resolve';

    /**
     * @param {string} event
     * @param {Function} handler
     */
    addEventListener(event, handler) {
        if (!this.listeners[event]) {
            this.listeners[event] = [];
        }
        this.listeners[event].push(handler);
    }

    play() {
        this.paused = false;
        if (this.playBehavior === 'reject') {
            return Promise.reject(new Error('autoplay blocked'));
        }

        return Promise.resolve();
    }

    pause() {
        this.paused = true;
    }

    /** @param {string} event */
    emit(event) {
        for (const handler of this.listeners[event] ?? []) {
            handler();
        }
    }
}

function installDom() {
    /** @type {Map<string, any>} */
    const shells = new Map();

    const documentMock = {
        /**
         * @param {string} selector
         */
        querySelectorAll(selector) {
            if (selector === '[data-playback-shell]' || selector === '[data-playback-shell][data-audio-src]') {
                return [...shells.values()].filter((shell) => {
                    if (selector.includes('[data-audio-src]')) {
                        return Boolean(shell.getAttribute('data-audio-src'));
                    }

                    return true;
                });
            }

            if (selector === '[data-autoplay-blocked]') {
                return [];
            }

            return [];
        },
        /**
         * @param {string} selector
         */
        querySelector(selector) {
            const blocked = selector.match(/^\[data-autoplay-blocked="(.+)"\]$/);
            if (blocked) {
                return null;
            }

            return null;
        },
        /**
         * @param {string} id
         * @param {string} url
         */
        addShell(id, url) {
            const playIcon = {
                classList: {
                    /** @type {Set<string>} */
                    tokens: new Set(),
                    /**
                     * @param {string} token
                     * @param {boolean} force
                     */
                    toggle(token, force) {
                        if (force) {
                            this.tokens.add(token);
                        } else {
                            this.tokens.delete(token);
                        }
                    },
                },
            };
            const stopIcon = {
                classList: {
                    /** @type {Set<string>} */
                    tokens: new Set(['hidden']),
                    /**
                     * @param {string} token
                     * @param {boolean} force
                     */
                    toggle(token, force) {
                        if (force) {
                            this.tokens.add(token);
                        } else {
                            this.tokens.delete(token);
                        }
                    },
                },
            };
            /** @type {Record<string, string>} */
            const attrs = {
                'data-playback-shell': id,
                'data-audio-src': url,
            };
            const toggle = {
                disabled: true,
                /**
                 * @param {string} name
                 * @param {string} value
                 */
                setAttribute(name, value) {
                    attrs[name] = value;
                },
            };
            const shell = {
                /**
                 * @type {{ tokens: Set<string>, toggle: (token: string, force: boolean) => void }}
                 */
                classList: {
                    tokens: new Set(['hidden']),
                    /**
                     * @param {string} token
                     * @param {boolean} force
                     */
                    toggle(token, force) {
                        if (force) {
                            this.tokens.add(token);
                        } else {
                            this.tokens.delete(token);
                        }
                    },
                },
                /**
                 * @param {string} name
                 */
                getAttribute(name) {
                    return attrs[name] ?? null;
                },
                /**
                 * @param {string} name
                 * @param {string} value
                 */
                setAttribute(name, value) {
                    attrs[name] = value;
                },
                /**
                 * @param {string} sel
                 */
                querySelector(sel) {
                    if (sel.includes('data-playback-toggle')) {
                        return toggle;
                    }
                    if (sel === '[data-playback-icon-play]') {
                        return playIcon;
                    }
                    if (sel === '[data-playback-icon-stop]') {
                        return stopIcon;
                    }

                    return null;
                },
            };
            shells.set(id, shell);
            return shell;
        },
        clear() {
            shells.clear();
        },
    };

    globalThis.document = documentMock;
    return documentMock;
}

beforeEach(() => {
    MockAudio.instances = [];
    MockAudio.nextPlayBehavior = 'resolve';
    globalThis.Audio = MockAudio;
    installDom();
    reset();
});
describe('FIFO autoplay coordinator', () => {
    test('buffers later-ready audio until earlier turn is ready and finishes', async () => {
        syncOrder(['turn-1', 'turn-2']);
        markReady('turn-2', 'https://example.test/2.wav');

        expect(getState().playing).toBe(false);
        expect(getState().playingId).toBeNull();
        expect(controlVisible('turn-2')).toBe(false);

        markReady('turn-1', 'https://example.test/1.wav');
        await Promise.resolve();

        expect(getState().playing).toBe(true);
        expect(getState().playingId).toBe('turn-1');
        expect(MockAudio.instances).toHaveLength(1);
        expect(MockAudio.instances[0].url).toBe('https://example.test/1.wav');
        expect(controlVisible('turn-2')).toBe(false);
        expect(controlEnabled('turn-2')).toBe(false);

        MockAudio.instances[0].emit('ended');
        await Promise.resolve();

        expect(getState().playingId).toBe('turn-2');
        expect(MockAudio.instances).toHaveLength(2);
        expect(MockAudio.instances[1].url).toBe('https://example.test/2.wav');
        expect(controlVisible('turn-2')).toBe(true);
    });

    test('skips failed earlier turns and plays the next ready turn', async () => {
        syncOrder(['turn-1', 'turn-2']);
        markReady('turn-2', 'https://example.test/2.wav');
        markFailed('turn-1');
        await Promise.resolve();

        expect(getState().playingId).toBe('turn-2');
        expect(controlVisible('turn-2')).toBe(true);
        expect(controlEnabled('turn-2')).toBe(true);
    });

    test('ignores duplicate ready events without starting a second clip', async () => {
        syncOrder(['turn-1']);
        markReady('turn-1', 'https://example.test/1.wav');
        await Promise.resolve();
        markReady('turn-1', 'https://example.test/1-again.wav');
        await Promise.resolve();

        expect(MockAudio.instances).toHaveLength(1);
        expect(getState().playingId).toBe('turn-1');
    });

    test('does not autoplay restored history shells, but exposes their controls', () => {
        const dom = /** @type {any} */ (globalThis.document);
        dom.addShell('history-1', 'https://example.test/h1.wav');
        dom.addShell('history-2', 'https://example.test/h2.wav');

        syncOrder(['history-1', 'history-2']);
        hydrateFromDom();
        refreshControls();

        expect(getState().playing).toBe(false);
        expect(getState().autoplayEligible).toEqual([]);
        expect(controlVisible('history-1')).toBe(true);
        expect(controlVisible('history-2')).toBe(true);
        expect(controlEnabled('history-1')).toBe(true);
        expect(controlEnabled('history-2')).toBe(true);
    });

    test('disables manual controls for other turns while a clip is playing', async () => {
        const dom = /** @type {any} */ (globalThis.document);
        dom.addShell('turn-1', 'https://example.test/1.wav');
        dom.addShell('turn-2', 'https://example.test/2.wav');

        syncOrder(['turn-1', 'turn-2']);
        markReady('turn-1', 'https://example.test/1.wav');
        markReady('turn-2', 'https://example.test/2.wav');
        await Promise.resolve();

        expect(getState().playingId).toBe('turn-1');
        expect(controlEnabled('turn-1')).toBe(true);
        expect(controlVisible('turn-2')).toBe(false);
        expect(controlEnabled('turn-2')).toBe(false);

        // Even after forcing visibility conditions via settled state checks, playManual must no-op for turn-2.
        playManual('turn-2');
        expect(getState().playingId).toBe('turn-1');
        expect(MockAudio.instances).toHaveLength(1);
    });

    test('stop toggle settles the active turn and advances FIFO', async () => {
        syncOrder(['turn-1', 'turn-2']);
        markReady('turn-1', 'https://example.test/1.wav');
        markReady('turn-2', 'https://example.test/2.wav');
        await Promise.resolve();

        expect(getState().playingId).toBe('turn-1');
        expect(controlState('turn-1')).toBe('playing');
        expect(MockAudio.instances).toHaveLength(1);
        expect(MockAudio.instances[0].paused).toBe(false);

        toggleManual('turn-1');
        await Promise.resolve();

        expect(getState().played).toContain('turn-1');
        expect(getState().playingId).toBe('turn-2');
        expect(controlState('turn-1')).toBe('idle');
        expect(MockAudio.instances).toHaveLength(2);
        expect(MockAudio.instances[0].paused).toBe(true);
    });

    test('stop on the active turn does not leave a resumable paused clip', async () => {
        syncOrder(['turn-1']);
        markReady('turn-1', 'https://example.test/1.wav');
        await Promise.resolve();

        toggleManual('turn-1');
        await Promise.resolve();

        expect(getState().playing).toBe(false);
        expect(getState().playingId).toBeNull();
        expect(getState().played).toContain('turn-1');
        expect('paused' in getState()).toBe(false);

        toggleManual('turn-1');
        await Promise.resolve();

        expect(getState().playingId).toBe('turn-1');
        expect(MockAudio.instances).toHaveLength(2);
    });

    test('autoplay rejection marks blocked and resume continues FIFO', async () => {
        MockAudio.nextPlayBehavior = 'reject';
        syncOrder(['turn-1', 'turn-2']);
        markReady('turn-1', 'https://example.test/1.wav');
        markReady('turn-2', 'https://example.test/2.wav');
        await Promise.resolve();
        await Promise.resolve();

        expect(getState().blocked).toBe(true);
        expect(getState().playing).toBe(false);
        expect(getState().playingId).toBe('turn-1');

        MockAudio.nextPlayBehavior = 'resolve';
        resumeFromUser('turn-1');
        await Promise.resolve();

        expect(getState().blocked).toBe(false);
        expect(getState().playingId).toBe('turn-1');

        MockAudio.instances.at(-1)?.emit('ended');
        await Promise.resolve();

        expect(getState().playingId).toBe('turn-2');
    });

    test('audio error settles the turn and continues the queue', async () => {
        syncOrder(['turn-1', 'turn-2']);
        markReady('turn-1', 'https://example.test/1.wav');
        markReady('turn-2', 'https://example.test/2.wav');
        await Promise.resolve();

        MockAudio.instances[0].emit('error');
        await Promise.resolve();

        expect(getState().played).toContain('turn-1');
        expect(getState().playingId).toBe('turn-2');
    });
});
