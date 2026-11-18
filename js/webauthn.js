import { browserSupportsWebAuthn, startAuthentication, startRegistration } from '@simplewebauthn/browser';

class WebAuthnRequestError extends Error {}

async function post(url, body) {
    const response = await fetch(url, {method: 'POST', body, credentials: 'same-origin', headers: {'Accept': 'application/json'}});

    if (!response.ok) {
        const result = await response.json().catch(() => ({}));
        throw new WebAuthnRequestError(result?.error);
    }

    return response.json();
}

for (const form of document.querySelectorAll('form[data-webauthn]')) {
    const error = form.querySelector('[data-webauthn-error]');
    const button = form.querySelector('button');

    if (!browserSupportsWebAuthn()) {
        button.disabled = true;
        error.textContent = error.dataset.unsupported;
        error.hidden = false;
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();

        if (button.disabled) {
            return;
        }

        error.hidden = true;
        button.disabled = true;

        try {
            const body = new FormData(form);
            const optionsJSON = await post(form.dataset.options, body);
            const credential = form.dataset.webauthn === 'register'
                ? await startRegistration({optionsJSON})
                : await startAuthentication({optionsJSON});
            const contents = JSON.stringify(credential);

            if (form.dataset.webauthn === 'login') {
                const input = form.elements.namedItem(form.dataset.codeName);
                input.value = contents;
                HTMLFormElement.prototype.submit.call(form);
            } else {
                body.set('credential', contents);
                const result = await post(form.action, body);
                window.location.assign(result.redirect);
            }
        } catch (exception) {
            if (exception.name === 'NotAllowedError') {
                error.textContent = error.dataset.cancelled;
            } else if (exception.name === 'InvalidStateError') {
                error.textContent = error.dataset.alreadyRegistered;
            } else {
                error.textContent = exception instanceof WebAuthnRequestError && exception.message
                    ? exception.message
                    : error.dataset.failed;
            }

            error.hidden = false;
        } finally {
            button.disabled = false;
        }
    });
}
