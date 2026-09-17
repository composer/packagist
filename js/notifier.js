import { Toast } from 'bootstrap';

const active = [];

let container = null;

function getContainer() {
    if (container) {
        return container;
    }
    container = document.querySelector('.notifications-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'notifications-container toast-container position-fixed top-0 start-50 translate-middle-x p-3';
        document.body.appendChild(container);
    }
    return container;
}

function spinner() {
    const el = document.createElement('span');
    el.className = 'spinner-border spinner-border-sm me-2';
    el.setAttribute('aria-hidden', 'true');
    return el;
}

function closeButton() {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn-close';
    btn.setAttribute('data-bs-dismiss', 'toast');
    btn.setAttribute('aria-label', 'Close');
    return btn;
}

function log(msg, options = {}, details = undefined) {
    const autohide = options.timeout !== undefined && options.timeout > 0;

    const el = document.createElement('div');
    el.className = 'toast';
    el.setAttribute('role', 'alert');
    el.setAttribute('aria-live', 'assertive');
    el.setAttribute('aria-atomic', 'true');

    if (details) {
        const header = document.createElement('div');
        header.className = 'toast-header';

        if (options.spinner) {
            header.appendChild(spinner());
        }

        const strong = document.createElement('strong');
        strong.className = 'me-auto';
        strong.textContent = msg;
        header.appendChild(strong);

        if (!autohide) {
            header.appendChild(closeButton());
        }

        const body = document.createElement('div');
        body.className = 'toast-body';
        body.innerHTML = details;

        el.appendChild(header);
        el.appendChild(body);
    } else {
        el.classList.add('align-items-center');

        const flex = document.createElement('div');
        flex.className = 'd-flex';

        const body = document.createElement('div');
        body.className = 'toast-body';
        if (options.spinner) {
            body.appendChild(spinner());
        }
        body.appendChild(document.createTextNode(msg));
        flex.appendChild(body);

        if (!autohide) {
            const btn = closeButton();
            btn.classList.add('me-2', 'm-auto');
            flex.appendChild(btn);
        }

        el.appendChild(flex);
    }

    // animation:false is not just cosmetic - show() queues a transition callback that dereferences
    // the element, so a toast disposed within the ~150ms transition (a fast-failing request
    // clearing its own progress toast) would throw from that callback after dispose() nulled it
    const instance = new Toast(el, {
        animation: options.animation !== false,
        ...(autohide ? { delay: options.timeout } : { autohide: false }),
    });
    const entry = { el, instance };

    el.addEventListener('hidden.bs.toast', () => {
        const i = active.indexOf(entry);
        if (i !== -1) {
            active.splice(i, 1);
        }
        el.remove();
    });

    if (autohide) {
        el.addEventListener('click', () => instance.hide());
    }

    active.push(entry);
    getContainer().appendChild(el);
    instance.show();

    return entry;
}

// without an entry this clears every toast, which is what the ajax error handler and the
// job-completion path both want
function remove(entry = undefined) {
    let removing;
    if (entry) {
        const i = active.indexOf(entry);
        removing = i === -1 ? [] : active.splice(i, 1);
    } else {
        removing = active.splice(0);
    }

    removing.forEach(({ el, instance }) => {
        instance.dispose();
        el.remove();
    });
}

export default { log, remove };
