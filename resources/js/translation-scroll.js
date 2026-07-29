/**
 * Chat thread auto-scroll.
 *
 * Presentation contract (Blade hooks):
 * - [data-translation-scroll] — overflow scroll container for the chat thread
 * - [data-turn-id]            — turn articles inside the scroll container (newest last)
 *
 * Behavior:
 * - Initial hydration / history restore always pins to the bottom.
 * - After Livewire morphs, pin to the bottom only when the newest data-turn-id changes.
 * - Status, typing, and other in-place updates do not jump the viewport.
 */

/** @type {Element | null} */
let knownContainer = null;

/** @type {string | null} */
let lastNewestTurnId = null;

let hydrated = false;

/**
 * @param {ParentNode} root
 * @returns {string | null}
 */
export function newestTurnId(root) {
    const turns = root.querySelectorAll('[data-turn-id]');
    if (turns.length === 0) {
        return null;
    }

    const id = turns[turns.length - 1].getAttribute('data-turn-id');

    return typeof id === 'string' && id !== '' ? id : null;
}

/**
 * @param {Element & { scrollTop: number, scrollHeight: number }} container
 */
export function scrollToBottom(container) {
    container.scrollTop = container.scrollHeight;
}

/**
 * Sync scroll position after Livewire morph / initial paint / navigation.
 */
export function reconcileScroll() {
    const container = document.querySelector('[data-translation-scroll]');
    if (!container) {
        knownContainer = null;
        lastNewestTurnId = null;
        hydrated = false;

        return;
    }

    if (container !== knownContainer) {
        knownContainer = container;
        lastNewestTurnId = null;
        hydrated = false;
    }

    const newest = newestTurnId(container);

    if (!hydrated) {
        if (newest !== null) {
            scrollToBottom(container);
        }

        lastNewestTurnId = newest;
        hydrated = true;

        return;
    }

    if (newest !== null && newest !== lastNewestTurnId) {
        scrollToBottom(container);
        lastNewestTurnId = newest;

        return;
    }

    if (newest === null) {
        lastNewestTurnId = null;
    }
}

/** @internal test helper */
export function resetScroll() {
    knownContainer = null;
    lastNewestTurnId = null;
    hydrated = false;
}

/** @internal test helper */
export function scrollState() {
    return {
        knownContainer,
        lastNewestTurnId,
        hydrated,
    };
}
