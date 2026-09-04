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
        body.textContent = msg;
        flex.appendChild(body);

        if (!autohide) {
            const btn = closeButton();
            btn.classList.add('me-2', 'm-auto');
            flex.appendChild(btn);
        }

        el.appendChild(flex);
    }

    const instance = new Toast(el, autohide ? { delay: options.timeout } : { autohide: false });
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
}

function remove() {
    active.splice(0).forEach(({ el, instance }) => {
        instance.dispose();
        el.remove();
    });
}

export default { log, remove };
