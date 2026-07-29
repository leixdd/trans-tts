import { afterEach, beforeEach, describe, expect, test } from 'bun:test';
import {
    graphemes,
    reconcileTyping,
    resetTyping,
    revealTranslation,
    typingTargets,
} from './translation-typing.js';

/**
 * @param {string[]} initial
 */
function classList(initial = []) {
    const tokens = new Set(initial);

    return {
        tokens,
        /**
         * @param {string} token
         */
        add(token) {
            tokens.add(token);
        },
        /**
         * @param {string} token
         */
        remove(token) {
            tokens.delete(token);
        },
        /**
         * @param {string} token
         */
        contains(token) {
            return tokens.has(token);
        },
        /**
         * @param {string} token
         * @param {boolean} [force]
         */
        toggle(token, force) {
            if (force === true) {
                tokens.add(token);
            } else if (force === false) {
                tokens.delete(token);
            } else if (tokens.has(token)) {
                tokens.delete(token);
            } else {
                tokens.add(token);
            }
        },
    };
}

/**
 * @param {string} turnId
 * @param {{ status?: string, translation?: string, hidden?: boolean }} [options]
 */
function makeArticle(turnId, options = {}) {
    const status = options.status ?? 'translating';
    const translation = options.translation ?? '';
    const hidden = options.hidden ?? translation === '';

    /** @type {Record<string, string>} */
    const statusAttrs = { 'data-turn-status': status };
    const statusEl = {
        textContent: status,
        /**
         * @param {string} name
         */
        getAttribute(name) {
            return statusAttrs[name] ?? null;
        },
        /**
         * @param {string} name
         * @param {string} value
         */
        setAttribute(name, value) {
            statusAttrs[name] = value;
        },
    };

    const writing = {
        classList: classList(translation !== '' ? ['hidden'] : []),
    };

    /** @type {Record<string, string>} */
    const articleAttrs = { 'data-turn-id': turnId };

    /** @type {any} */
    const article = {
        /**
         * @param {string} name
         */
        getAttribute(name) {
            return articleAttrs[name] ?? null;
        },
        /**
         * @param {string} name
         */
        hasAttribute(name) {
            return name in articleAttrs;
        },
    };

    const node = {
        textContent: translation,
        classList: classList(hidden ? ['hidden', 'translation-text'] : ['translation-text']),
        /**
         * @param {string} selector
         */
        closest(selector) {
            if (selector === '[data-turn-id]') {
                return article;
            }

            return null;
        },
    };

    article.querySelector = (selector) => {
        if (selector === '[data-turn-translation]') {
            return node;
        }
        if (selector === '[data-turn-writing]') {
            return writing;
        }
        if (selector === '[data-turn-status]') {
            return statusEl;
        }

        return null;
    };

    return { article, node, writing, statusEl };
}

/** @type {ReturnType<typeof makeArticle>[]} */
let articles = [];

function installDocument() {
    globalThis.document = {
        /**
         * @param {string} selector
         */
        querySelectorAll(selector) {
            if (selector === '[data-turn-id]') {
                return articles.map((entry) => entry.article);
            }

            return [];
        },
    };
}

describe('graphemes', () => {
    test('splits Japanese characters', () => {
        expect(graphemes('こんにちは')).toEqual(['こ', 'ん', 'に', 'ち', 'は']);
    });
});

describe('revealTranslation', () => {
    beforeEach(() => {
        resetTyping();
        articles = [];
        installDocument();
    });

    afterEach(() => {
        resetTyping();
        articles = [];
    });

    test('hides writing indicator and reveals text instantly when requested', () => {
        const entry = makeArticle('t1');
        articles.push(entry);

        revealTranslation('t1', entry.node, 'こんにちは', { instant: true });

        expect(entry.node.textContent).toBe('こんにちは');
        expect(entry.node.classList.contains('hidden')).toBe(false);
        expect(entry.writing.classList.contains('hidden')).toBe(true);
        expect(entry.node.classList.contains('is-typing')).toBe(false);
    });

    test('types toward growing streamed text', async () => {
        const entry = makeArticle('t2');
        articles.push(entry);

        revealTranslation('t2', entry.node, 'こん');
        expect(entry.writing.classList.contains('hidden')).toBe(true);
        expect(entry.node.classList.contains('is-typing')).toBe(true);

        revealTranslation('t2', entry.node, 'こんにちは');

        await new Promise((resolve) => setTimeout(resolve, 400));

        expect(entry.node.textContent).toBe('こんにちは');
        expect(entry.node.classList.contains('is-typing')).toBe(false);
        expect(typingTargets().get('t2')).toBe('こんにちは');
    });

    test('empty text restores writing indicator', () => {
        const entry = makeArticle('t3', { translation: 'あ', hidden: false });
        articles.push(entry);
        typingTargets().set('t3', 'あ');

        revealTranslation('t3', entry.node, '');

        expect(entry.node.textContent).toBe('');
        expect(entry.node.classList.contains('hidden')).toBe(true);
        expect(entry.writing.classList.contains('hidden')).toBe(false);
    });
});

describe('reconcileTyping', () => {
    beforeEach(() => {
        resetTyping();
        articles = [];
        installDocument();
    });

    afterEach(() => {
        resetTyping();
        articles = [];
    });

    test('first pass does not replay history text', () => {
        const entry = makeArticle('hist', { status: 'completed', translation: '完成', hidden: false });
        articles.push(entry);

        reconcileTyping();

        expect(typingTargets().get('hist')).toBe('完成');
        expect(entry.node.textContent).toBe('完成');
        expect(entry.node.classList.contains('is-typing')).toBe(false);
    });

    test('shows writing indicator for empty in-flight turns', () => {
        const entry = makeArticle('flight', { status: 'translating', translation: '' });
        articles.push(entry);

        reconcileTyping();

        expect(entry.writing.classList.contains('hidden')).toBe(false);
    });

    test('animates new text after hydrate', async () => {
        const entry = makeArticle('live', { status: 'translating', translation: '' });
        articles.push(entry);
        reconcileTyping();

        entry.node.classList.remove('hidden');
        entry.node.textContent = 'はい';
        entry.statusEl.setAttribute('data-turn-status', 'synthesizing');

        reconcileTyping();

        expect(entry.node.classList.contains('is-typing')).toBe(true);

        await new Promise((resolve) => setTimeout(resolve, 200));

        expect(entry.node.textContent).toBe('はい');
        expect(entry.node.classList.contains('is-typing')).toBe(false);
    });
});
