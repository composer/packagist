import notifier from './notifier';

export class RequestError extends Error {
    constructor(message, responseText = '') {
        super(message);
        this.responseText = responseText;
    }
}

export function showRequestError(responseText) {
    let message, details;

    notifier.remove();

    if (responseText) {
        try {
            const resp = JSON.parse(responseText);
            if (resp.status && resp.status === 'error') {
                message = resp.message;
                details = resp.details;
            }
        } catch (e) {}
    }

    notifier.log(message || "We're so sorry, something is wrong on our end.", {}, details);
}

// the non-file fields of a form, as a browser would submit them
export function formParams(form) {
    return new URLSearchParams(new FormData(form));
}

/**
 * Resolves with the parsed JSON body (null when empty). Failures reject with an RequestError after
 * showing the server's message in a toast, unless showError is false.
 */
export function request(url, {method = 'GET', params = null, cache = true, showError = true} = {}) {
    method = method.toUpperCase();
    params = new URLSearchParams(params || undefined);

    let body;
    if (method === 'GET' || method === 'HEAD') {
        if (!cache) {
            params.set('_', String(Date.now()));
        }
        const query = params.toString();
        if (query) {
            url += (url.includes('?') ? '&' : '?') + query;
        }
    } else {
        body = params;
    }

    return fetch(url, {
        method,
        body,
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        cache: cache ? 'default' : 'no-store',
        credentials: 'same-origin',
    })
        .then((response) => response.text().then((text) => {
            if (!response.ok) {
                throw new RequestError('HTTP ' + response.status, text);
            }
            try {
                return text ? JSON.parse(text) : null;
            } catch (e) {
                throw new RequestError('Invalid JSON response', text);
            }
        }), (e) => {
            throw new RequestError(e.message);
        })
        .catch((e) => {
            if (showError) {
                showRequestError(e.responseText);
            }
            throw e;
        });
}

// for call sites with nothing to do on failure beyond the toast request() already showed
export function ignoreRequestError(e) {
    if (!(e instanceof RequestError)) {
        throw e;
    }
}
