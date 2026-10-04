/*
 * HashOver: loads a page's comments and enhances them. Without this script,
 * comments included with PHP still work through plain links and forms.
 *
 *     <link rel="stylesheet" href="/hashover/hashover.css">
 *     <div id="hashover"></div>
 *     <script type="module" src="/hashover/hashover.js"></script>
 *
 * Options, as attributes of the container:
 *   data-hashover-url       canonical URL of the page (default: <link rel="canonical"> or the address)
 *   data-hashover-language  interface language, e.g. "fr" (default: the configured one)
 *   data-hashover-title     page title shown in the form heading (default: the document title)
 *
 * Comment counts: <span data-hashover-count="https://example.com/page"></span>,
 * with an optional data-hashover-language as well.
 *
 * With Cloudflare Turnstile enabled, this script loads the widget from
 * challenges.cloudflare.com into the comment form, exchanges its token for a
 * pass cookie and enables liking, replying and posting until the pass expires.
 *
 * @license AGPL-3.0-or-later
 */

const endpoint = new URL('index.php', import.meta.url);
const turnstileScript = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

/** Threads on this page */
const containers = [...new Set(document.querySelectorAll('#hashover, [data-hashover]'))];

const fallbackText = {
    confirmDelete: 'Delete this comment? This can’t be undone.',
    loading: 'Loading comments…',
    failed: 'Something went wrong. Please try again.',
    showImage: 'Show image',
    image: 'Image posted by the commenter',
    verifyLoading: 'Loading the security check…',
    verifyUnavailable: 'The security check couldn’t be loaded.',
    verifyFailed: 'The security check failed. Please try again or reload the page.',
};

/** URL of the page the comments belong to */
function pageUrl(container) {
    if (container.dataset.hashoverUrl) {
        return container.dataset.hashoverUrl;
    }

    const canonical = document.querySelector('link[rel="canonical"]');
    const url = new URL(canonical ? canonical.href : location.href, location.href);
    url.hash = '';

    return url.href;
}

function text(container, key) {
    const thread = container.querySelector('.hashover-thread');

    try {
        return JSON.parse(thread?.dataset.hashoverText ?? '{}')[key] ?? fallbackText[key];
    } catch {
        return fallbackText[key];
    }
}

function apiUrl(container, parameters) {
    const url = new URL(endpoint);
    url.searchParams.set('url', pageUrl(container));
    url.searchParams.set('title', container.dataset.hashoverTitle || document.title);

    if (container.dataset.hashoverLanguage) {
        url.searchParams.set('language', container.dataset.hashoverLanguage);
    }

    for (const [name, value] of Object.entries(parameters)) {
        url.searchParams.set(name, value);
    }

    return url;
}

async function request(url, options = {}) {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    const isJson = (response.headers.get('Content-Type') ?? '').includes('application/json');
    const body = isJson ? await response.json() : await response.text();

    if (!response.ok && !isJson) {
        throw new Error(String(body));
    }

    return body;
}

/** Announce a message to screen readers and show it */
function announce(container, message) {
    const status = container.querySelector('#hashover-status');

    if (!status || !message) {
        return;
    }

    // Changing the content after a pause makes sure it is announced
    status.replaceChildren();
    setTimeout(() => {
        const paragraph = document.createElement('p');
        paragraph.textContent = message;
        status.replaceChildren(paragraph);
    }, 100);
}

function focus(element) {
    if (!element) {
        return;
    }

    if (!element.matches('a, button, input, textarea, select, [tabindex]')) {
        element.setAttribute('tabindex', '-1');
    }

    element.focus();
}

/** Load (or reload) the thread into its container */
async function load(container, parameters = {}) {
    container.setAttribute('aria-busy', 'true');

    if (!container.querySelector('.hashover-thread')) {
        container.textContent = fallbackText.loading;
    }

    try {
        render(container, await request(apiUrl(container, { action: 'thread', ...parameters }), { headers: { Accept: 'text/html' } }));
    } catch (error) {
        container.textContent = fallbackText.failed;
        console.error('HashOver:', error);
    } finally {
        container.removeAttribute('aria-busy');
    }
}

function render(container, html) {
    container.innerHTML = html;
    enhance(container);
}

/** Turn plain links into buttons that open forms in place */
function enhance(container) {
    for (const link of container.querySelectorAll('a[data-hashover-open]')) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = link.className;
        button.innerHTML = link.innerHTML;
        Object.assign(button.dataset, link.dataset);
        const slotId = link.getAttribute('aria-controls');
        const slot = container.querySelector('#' + CSS.escape(slotId));
        button.setAttribute('aria-controls', slotId);
        button.setAttribute('aria-expanded', String(Boolean(slot?.querySelector('.hashover-form-' + link.dataset.hashoverOpen))));
        link.replaceWith(button);
    }

    const thread = container.querySelector('.hashover-thread');

    if (thread?.dataset.hashoverTurnstile) {
        setVerified(Number(thread.dataset.hashoverVerified) || 0);
    }

    if (thread && !document.querySelector('link[rel="alternate"][data-hashover]')) {
        const feed = container.querySelector('a[type="application/rss+xml"]');

        if (feed) {
            const link = document.createElement('link');
            link.rel = 'alternate';
            link.type = 'application/rss+xml';
            link.href = feed.href;
            link.title = feed.textContent;
            link.dataset.hashover = '';
            document.head.append(link);
        }
    }
}

/** Open or close a reply or edit form */
async function toggleForm(container, button) {
    const slot = container.querySelector('#' + CSS.escape(button.getAttribute('aria-controls')));
    const isOpen = button.getAttribute('aria-expanded') === 'true';

    for (const other of container.querySelectorAll(`[aria-controls="${CSS.escape(slot.id)}"]`)) {
        other.setAttribute('aria-expanded', 'false');
    }

    if (isOpen) {
        slot.replaceChildren();
        return;
    }

    try {
        const html = await request(apiUrl(container, { action: 'form', [button.dataset.hashoverOpen]: button.dataset.hashoverId }), { headers: { Accept: 'text/html' } });
        slot.innerHTML = html;
        gate(container);
        button.setAttribute('aria-expanded', 'true');
        focus(slot.querySelector('textarea, button'));
    } catch (error) {
        announce(container, text(container, 'failed'));
        console.error('HashOver:', error);
    }
}

function closeForm(container, slot) {
    slot.replaceChildren();

    const opener = container.querySelector(`[aria-controls="${CSS.escape(slot.id)}"][aria-expanded="true"]`);

    if (opener) {
        opener.setAttribute('aria-expanded', 'false');
        focus(opener);
    }
}

function showError(form, message, field) {
    const error = form.querySelector('.hashover-error');

    for (const invalid of form.querySelectorAll('[aria-invalid="true"]')) {
        invalid.removeAttribute('aria-invalid');
    }

    if (!error) {
        return;
    }

    error.textContent = message;
    error.hidden = false;

    const input = field ? form.querySelector(`[name="${CSS.escape(field)}"]`) : null;

    if (input) {
        input.setAttribute('aria-invalid', 'true');

        const describedBy = (input.getAttribute('aria-describedby') ?? '').split(' ').filter(Boolean);

        if (!describedBy.includes(error.id)) {
            input.setAttribute('aria-describedby', [...describedBy, error.id].join(' '));
        }

        focus(input);
    }
}

async function submit(container, form, submitter) {
    const data = new FormData(form, submitter);

    if (submitter?.matches('[data-hashover-delete]')) {
        if (!confirm(text(container, 'confirmDelete'))) {
            return;
        }

        data.set('confirm', '1');
    }

    form.setAttribute('aria-busy', 'true');

    for (const button of form.querySelectorAll('button')) {
        button.disabled = true;
    }

    try {
        // Read the attribute: form.action would return the buttons named "action"
        const action = new URL(form.getAttribute('action') ?? '', location.href);
        const result = await request(action, { method: 'POST', body: data, headers: { Accept: 'application/json' } });

        if (!result.ok) {
            if (result.error === 'error.verification_required') {
                expire();
            }

            showError(form, result.message, result.field);
            announce(container, result.message);
            return;
        }

        if ('liked' in result) {
            form.querySelector('.hashover-like').setAttribute('aria-pressed', String(result.liked));
            form.closest('.hashover-actions').querySelector('.hashover-likes').textContent = result.likes > 0 ? result.likesText : '';
            announce(container, result.message);
            return;
        }

        render(container, result.html);
        announce(container, result.message);
        updateCounts();
        focus(container.querySelector('#' + CSS.escape(result.focus)) ?? container.querySelector('#hashover-comments-heading'));
    } catch (error) {
        showError(form, text(container, 'failed'), null);
        console.error('HashOver:', error);
    } finally {
        form.removeAttribute('aria-busy');

        for (const button of form.querySelectorAll('button')) {
            button.disabled = false;
        }
    }
}

// Cloudflare Turnstile

/** Time (ms) until which the visitor's pass is valid, as last told by the server */
let verifiedUntil = 0;
let expiryTimer;
let turnstileLoaded;

/** Turnstile widget ids and their elements */
const widgets = new Map();

function isVerified() {
    return Date.now() < verifiedUntil;
}

/** Record the seconds left on the visitor's pass and update every thread */
function setVerified(seconds) {
    verifiedUntil = seconds > 0 ? Date.now() + seconds * 1000 : 0;
    clearTimeout(expiryTimer);

    if (seconds > 0) {
        expiryTimer = setTimeout(expire, seconds * 1000);
    }

    for (const container of containers) {
        gate(container);
    }
}

/** The pass has expired: disable the controls again and run a new check */
function expire() {
    forgetRemovedWidgets();

    for (const id of widgets.keys()) {
        window.turnstile.reset(id);
    }

    setVerified(0);
}

/** Forget the widgets of threads that were rendered again */
function forgetRemovedWidgets() {
    for (const [id, element] of widgets) {
        if (!element.isConnected) {
            widgets.delete(id);

            try {
                window.turnstile.remove(id);
            } catch {
                // Turnstile may have noticed already
            }
        }
    }
}

/** Enable or disable the controls that need a pass, and show the check if needed */
function gate(container) {
    const thread = container.querySelector('.hashover-thread');

    if (!thread?.dataset.hashoverTurnstile) {
        return;
    }

    const verified = isVerified();

    for (const control of container.querySelectorAll('[data-hashover-gated]')) {
        const note = control.dataset.hashoverGated;
        const describedBy = (control.getAttribute('aria-describedby') ?? '').split(' ').filter((id) => id && id !== note);

        if (verified) {
            control.removeAttribute('aria-disabled');
        } else {
            control.setAttribute('aria-disabled', 'true');
            describedBy.push(note);
        }

        if (describedBy.length > 0) {
            control.setAttribute('aria-describedby', describedBy.join(' '));
        } else {
            control.removeAttribute('aria-describedby');
        }
    }

    for (const note of container.querySelectorAll('.hashover-gate-note')) {
        note.hidden = verified;
    }

    const box = container.querySelector('#hashover-verify');

    // Once verified, the check stays visible (showing its success) until the
    // thread is rendered again, so the form doesn't jump under the pointer
    if (box && !verified) {
        box.hidden = false;
        showWidget(container, thread, box);
    }
}

function loadTurnstile() {
    turnstileLoaded ??= new Promise((resolve, reject) => {
        if (window.turnstile) {
            resolve(window.turnstile);
            return;
        }

        const script = document.createElement('script');
        script.src = turnstileScript;
        script.onload = () => (window.turnstile ? resolve(window.turnstile) : reject(new Error('Turnstile is missing')));
        script.onerror = () => reject(new Error('Turnstile could not be loaded from ' + turnstileScript));
        document.head.append(script);
    }).catch((error) => {
        // Let a later attempt try again
        turnstileLoaded = undefined;
        throw error;
    });

    return turnstileLoaded;
}

async function showWidget(container, thread, box) {
    const slot = box.querySelector('[data-hashover-turnstile-widget]');
    const status = box.querySelector('[data-hashover-verify-status]');

    if (!slot || slot.dataset.hashoverWidget !== undefined) {
        return;
    }

    slot.dataset.hashoverWidget = '';
    status.textContent = text(container, 'verifyLoading');

    let turnstile;

    try {
        turnstile = await loadTurnstile();
    } catch (error) {
        status.textContent = text(container, 'verifyUnavailable');
        delete slot.dataset.hashoverWidget;
        console.error('HashOver:', error);
        return;
    }

    forgetRemovedWidgets();

    if (!slot.isConnected) {
        return;
    }

    status.textContent = '';
    let retries = 0;

    const id = turnstile.render(slot, {
        sitekey: thread.dataset.hashoverTurnstile,
        action: 'hashover',
        language: thread.lang || 'auto',
        theme: theme(thread),
        // The normal widget needs 300 pixels
        size: slot.clientWidth < 300 ? 'compact' : 'flexible',
        // The token is sent by verify(), not with the comment form
        'response-field': false,
        callback: (token) => verify(container, slot, status, token, () => {
            // A rejected token may be followed by a good one, but don't loop
            if (retries++ < 2) {
                turnstile.reset(id);
            }
        }),
        'error-callback': () => {
            status.textContent = text(container, 'verifyFailed');
            announce(container, status.textContent);
        },
    });

    widgets.set(id, slot);
}

/**
 * Light or dark widget, following the page like the stylesheet does (its
 * colours derive from the text colour) rather than the browser's preference
 */
function theme(element) {
    const rgb = getComputedStyle(element).color.match(/^rgba?\((\d+), (\d+), (\d+)/);

    if (!rgb) {
        return 'auto';
    }

    const [red, green, blue] = rgb.slice(1).map(Number);

    return 0.299 * red + 0.587 * green + 0.114 * blue > 128 ? 'dark' : 'light';
}

/** Exchange a Turnstile token for a pass */
async function verify(container, slot, status, token, retry) {
    const form = slot.closest('form');
    const data = new FormData();
    data.set('action', 'verify');
    data.set('token', token);

    for (const name of ['url', 'title', 'csrf', 'language']) {
        data.set(name, form.querySelector(`input[name="${name}"]`)?.value ?? '');
    }

    try {
        const action = new URL(form.getAttribute('action') ?? '', location.href);
        const result = await request(action, { method: 'POST', body: data, headers: { Accept: 'application/json' } });

        if (!result.ok) {
            status.textContent = result.message;
            announce(container, result.message);

            if (result.error === 'error.verification_failed') {
                retry();
            }

            return;
        }

        status.textContent = '';
        setVerified(result.remaining);
        announce(container, result.message);
    } catch (error) {
        status.textContent = text(container, 'verifyFailed');
        announce(container, status.textContent);
        console.error('HashOver:', error);
    }
}

function attach(container) {
    container.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-hashover-form]');

        if (form) {
            event.preventDefault();

            if (!event.submitter?.matches('[aria-disabled="true"]')) {
                submit(container, form, event.submitter);
            }
        }
    });

    // Escape closes an open reply or edit form
    container.addEventListener('keydown', (event) => {
        const slot = event.key === 'Escape' ? event.target.closest('.hashover-slot') : null;

        if (slot && slot.querySelector('form')) {
            event.preventDefault();
            closeForm(container, slot);
        }
    });

    container.addEventListener('click', (event) => {
        // Controls waiting for the security check lead to it instead
        const gated = event.target.closest('[data-hashover-gated][aria-disabled="true"]');

        if (gated) {
            event.preventDefault();
            focus(container.querySelector('#hashover-verify'));
            announce(container, container.querySelector('#' + CSS.escape(gated.dataset.hashoverGated))?.textContent.trim());
            return;
        }

        const target = event.target.closest('button[data-hashover-open], a[data-hashover-cancel], a[data-hashover-sort], a.hashover-image');

        if (!target) {
            return;
        }

        if (target.matches('button[data-hashover-open]')) {
            toggleForm(container, target);
        } else if (target.matches('a[data-hashover-cancel]')) {
            event.preventDefault();
            closeForm(container, target.closest('.hashover-slot') ?? target.closest('form'));
        } else if (target.matches('a[data-hashover-sort]')) {
            event.preventDefault();
            load(container, { sort: target.dataset.hashoverSort }).then(() => focus(container.querySelector('#hashover-comments-heading')));
        } else if (!target.querySelector('img')) {
            // Images from other websites are only loaded on request
            event.preventDefault();
            const image = document.createElement('img');
            image.src = target.href;
            image.alt = text(container, 'image');
            target.replaceChildren(image);
        }
    });
}

async function updateCounts() {
    for (const element of document.querySelectorAll('[data-hashover-count]')) {
        const url = new URL(endpoint);
        url.searchParams.set('action', 'count');
        url.searchParams.set('url', new URL(element.dataset.hashoverCount || location.href, location.href).href);

        if (element.dataset.hashoverLanguage) {
            url.searchParams.set('language', element.dataset.hashoverLanguage);
        }

        try {
            element.textContent = (await request(url, { headers: { Accept: 'application/json' } })).text;
        } catch (error) {
            console.error('HashOver:', error);
        }
    }
}

for (const container of containers) {
    attach(container);

    if (container.querySelector('.hashover-thread')) {
        enhance(container);
    } else {
        load(container);
    }
}

updateCounts();
