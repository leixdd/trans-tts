import { afterEach, beforeEach, describe, expect, test } from 'bun:test';
import {
    newestTurnId,
    reconcileScroll,
    resetScroll,
    scrollState,
    scrollToBottom,
} from './translation-scroll.js';

/**
 * @param {string} turnId
 */
function makeTurn(turnId) {
    /** @type {Record<string, string>} */
    const attrs = { 'data-turn-id': turnId };

    return {
        /**
         * @param {string} name
         */
        getAttribute(name) {
            return attrs[name] ?? null;
        },
    };
}

/**
 * @param {string[]} turnIds
 * @param {{ scrollTop?: number, scrollHeight?: number }} [options]
 */
function makeContainer(turnIds, options = {}) {
    const turns = turnIds.map((id) => makeTurn(id));

    return {
        scrollTop: options.scrollTop ?? 0,
        scrollHeight: options.scrollHeight ?? 1000,
        /**
         * @param {string} selector
         */
        querySelectorAll(selector) {
            if (selector === '[data-turn-id]') {
                return turns;
            }

            return [];
        },
    };
}

/** @type {ReturnType<typeof makeContainer> | null} */
let container = null;

function installDocument() {
    globalThis.document = {
        /**
         * @param {string} selector
         */
        querySelector(selector) {
            if (selector === '[data-translation-scroll]') {
                return container;
            }

            return null;
        },
    };
}

beforeEach(() => {
    resetScroll();
    container = null;
    installDocument();
});

afterEach(() => {
    resetScroll();
    container = null;
});

describe('newestTurnId', () => {
    test('returns the last turn id in document order', () => {
        const root = makeContainer(['a', 'b', 'c']);

        expect(newestTurnId(root)).toBe('c');
    });

    test('returns null for an empty thread', () => {
        const root = makeContainer([]);

        expect(newestTurnId(root)).toBeNull();
    });
});

describe('scrollToBottom', () => {
    test('sets scrollTop to scrollHeight', () => {
        const el = { scrollTop: 12, scrollHeight: 480 };

        scrollToBottom(el);

        expect(el.scrollTop).toBe(480);
    });
});

describe('reconcileScroll', () => {
    test('pins restored history to the bottom on initial reconciliation', () => {
        container = makeContainer(['old-1', 'old-2'], { scrollTop: 0, scrollHeight: 2000 });

        reconcileScroll();

        expect(container.scrollTop).toBe(2000);
        expect(scrollState().lastNewestTurnId).toBe('old-2');
        expect(scrollState().hydrated).toBe(true);
    });

    test('forces the bottom when a genuinely new newest turn appears', () => {
        container = makeContainer(['t1'], { scrollTop: 0, scrollHeight: 800 });
        reconcileScroll();
        expect(container.scrollTop).toBe(800);

        // User scrolled upward; a new turn is appended (same container identity).
        container.scrollTop = 40;
        container.scrollHeight = 1200;
        const turns = ['t1', 't2'].map((id) => makeTurn(id));
        container.querySelectorAll = (selector) => (selector === '[data-turn-id]' ? turns : []);

        reconcileScroll();

        expect(container.scrollTop).toBe(1200);
        expect(scrollState().lastNewestTurnId).toBe('t2');
    });

    test('does not jump for unchanged history or in-place status updates', () => {
        container = makeContainer(['t1'], { scrollTop: 0, scrollHeight: 900 });
        reconcileScroll();

        container.scrollTop = 120;
        container.scrollHeight = 950;

        reconcileScroll();
        reconcileScroll();

        expect(container.scrollTop).toBe(120);
        expect(scrollState().lastNewestTurnId).toBe('t1');
    });

    test('handles an empty thread without throwing', () => {
        container = makeContainer([], { scrollTop: 0, scrollHeight: 300 });

        reconcileScroll();

        expect(container.scrollTop).toBe(0);
        expect(scrollState().lastNewestTurnId).toBeNull();
        expect(scrollState().hydrated).toBe(true);
    });

    test('treats a replaced scroll container as a fresh initial paint', () => {
        const first = makeContainer(['a'], { scrollTop: 0, scrollHeight: 500 });
        container = first;
        reconcileScroll();
        first.scrollTop = 80;

        const second = makeContainer(['b', 'c'], { scrollTop: 0, scrollHeight: 1500 });
        container = second;
        reconcileScroll();

        expect(second.scrollTop).toBe(1500);
        expect(scrollState().lastNewestTurnId).toBe('c');
        expect(scrollState().knownContainer).toBe(second);
    });

    test('resetScroll clears state so the next reconcile pins to the bottom again', () => {
        container = makeContainer(['t1'], { scrollTop: 0, scrollHeight: 700 });
        reconcileScroll();
        container.scrollTop = 55;

        resetScroll();
        expect(scrollState()).toEqual({
            knownContainer: null,
            lastNewestTurnId: null,
            hydrated: false,
        });

        reconcileScroll();

        expect(container.scrollTop).toBe(700);
    });

    test('clears state when the scroll container is missing', () => {
        container = makeContainer(['t1'], { scrollTop: 0, scrollHeight: 400 });
        reconcileScroll();

        container = null;
        reconcileScroll();

        expect(scrollState()).toEqual({
            knownContainer: null,
            lastNewestTurnId: null,
            hydrated: false,
        });
    });
});
