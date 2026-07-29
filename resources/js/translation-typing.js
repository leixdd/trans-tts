/**
 * Writing / typing reveal for translation bubbles.
 *
 * Presentation contract (Blade hooks):
 * - [data-turn-translation] — Japanese text node; may start hidden while empty
 * - [data-turn-writing]     — in-flight writing indicator (dots + caret)
 * - [data-turn-id]          — turn article root
 */

/** @type {Map<string, string>} */
const targets = new Map();

/** @type {Map<string, { chars: string[], index: number, timer: ReturnType<typeof setTimeout> | null, node: Element }>} */
const sessions = new Map();

let hydrated = false;

const CHAR_MS = 28;
const CATCH_UP_MS = 12;

/**
 * @param {string} text
 * @returns {string[]}
 */
export function graphemes(text) {
    if (typeof Intl !== 'undefined' && typeof Intl.Segmenter === 'function') {
        return [...new Intl.Segmenter('ja', { granularity: 'grapheme' }).segment(text)].map(
            (part) => part.segment,
        );
    }

    return Array.from(text);
}

/**
 * @param {string} turnId
 */
function clearSession(turnId) {
    const session = sessions.get(turnId);
    if (!session) {
        return;
    }

    if (session.timer !== null) {
        clearTimeout(session.timer);
    }

    sessions.delete(turnId);
    session.node.classList.remove('is-typing');
}

/**
 * @param {Element} article
 * @param {boolean} show
 */
function setWritingVisible(article, show) {
    const writing = article.querySelector('[data-turn-writing]');
    if (!writing) {
        return;
    }

    writing.classList.toggle('hidden', !show);
}

/**
 * @param {Element} node
 * @param {string} text
 * @param {boolean} typing
 */
function paint(node, text, typing) {
    node.textContent = text;
    node.classList.toggle('is-typing', typing);
}

/**
 * @param {string} turnId
 * @param {Element} node
 * @param {string} target
 */
function tick(turnId, node, target) {
    const session = sessions.get(turnId);
    if (!session || session.node !== node) {
        return;
    }

    const currentTarget = targets.get(turnId) ?? target;
    if (currentTarget !== target) {
        session.chars = graphemes(currentTarget);
        target = currentTarget;
    }

    if (session.index >= session.chars.length) {
        paint(node, currentTarget, false);
        clearSession(turnId);
        return;
    }

    session.index += 1;
    const next = session.chars.slice(0, session.index).join('');
    const remaining = session.chars.length - session.index;
    paint(node, next, true);

    const delay = remaining > 24 ? CATCH_UP_MS : CHAR_MS;
    session.timer = setTimeout(() => tick(turnId, node, target), delay);
}

/**
 * Reveal translation text with a typing animation (or instantly).
 *
 * @param {string} turnId
 * @param {Element} node
 * @param {string} text
 * @param {{ instant?: boolean }} [options]
 */
export function revealTranslation(turnId, node, text, options = {}) {
    const instant = options.instant === true;
    const article = node.closest('[data-turn-id]');
    const previous = targets.get(turnId) ?? '';

    targets.set(turnId, text);

    if (text === '') {
        clearSession(turnId);
        paint(node, '', false);
        node.classList.add('hidden');
        if (article) {
            setWritingVisible(article, true);
        }
        return;
    }

    node.classList.remove('hidden');
    if (article) {
        setWritingVisible(article, false);
    }

    if (instant || previous === text) {
        clearSession(turnId);
        paint(node, text, false);
        return;
    }

    const existing = sessions.get(turnId);
    if (existing && existing.node === node) {
        existing.chars = graphemes(text);
        return;
    }

    clearSession(turnId);

    const chars = graphemes(text);
    let index = 0;

    // Continue from the last known target when streaming grows the string.
    // Otherwise start from empty so Livewire morphs (full text already in DOM) still type in.
    if (previous !== '' && text.startsWith(previous)) {
        index = graphemes(previous).length;
        paint(node, previous, true);
    } else {
        paint(node, '', true);
    }

    if (index >= chars.length) {
        paint(node, text, false);
        return;
    }

    sessions.set(turnId, { chars, index, timer: null, node });
    tick(turnId, node, text);
}

/**
 * Sync typing UI after Livewire morph / initial paint.
 * First pass marks existing text as already revealed (no replay on history).
 */
export function reconcileTyping() {
    document.querySelectorAll('[data-turn-id]').forEach((article) => {
        const turnId = article.getAttribute('data-turn-id');
        if (typeof turnId !== 'string' || turnId === '') {
            return;
        }

        const node = article.querySelector('[data-turn-translation]');
        const statusEl = article.querySelector('[data-turn-status]');
        const status = statusEl?.getAttribute('data-turn-status') ?? statusEl?.textContent?.trim() ?? '';
        const inFlight = ['queued', 'translating', 'synthesizing'].includes(status);

        if (!node) {
            setWritingVisible(article, false);
            return;
        }

        const text = node.classList.contains('hidden') ? '' : (node.textContent ?? '');
        const empty = text === '';

        setWritingVisible(article, inFlight && empty);

        if (empty) {
            if (!targets.has(turnId)) {
                targets.set(turnId, '');
            }
            return;
        }

        const previous = targets.get(turnId);

        if (!hydrated) {
            targets.set(turnId, text);
            paint(node, text, false);
            return;
        }

        if (previous === text) {
            if (!sessions.has(turnId)) {
                paint(node, text, false);
            }
            return;
        }

        revealTranslation(turnId, node, text);
    });

    hydrated = true;
}

/** @internal test helper */
export function resetTyping() {
    for (const turnId of sessions.keys()) {
        clearSession(turnId);
    }
    targets.clear();
    hydrated = false;
}

/** @internal test helper */
export function typingTargets() {
    return targets;
}
