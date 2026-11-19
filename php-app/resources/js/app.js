import './bootstrap';
import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;

// <x-money-input>: mirror the visible currency amount into its hidden
// integer-cents field, which is what the form actually submits.
function syncCents(input) {
    const hidden = document.getElementById(input.dataset.centsTarget);
    if (!hidden) {
        return;
    }
    const amount = Number.parseFloat(input.value);
    hidden.value = Number.isFinite(amount) ? String(Math.round(amount * 100)) : '';
}

document.addEventListener('input', (event) => {
    const input = event.target.closest('[data-cents-target]');
    if (input) {
        syncCents(input);
    }
});

document.querySelectorAll('[data-cents-target]').forEach(syncCents);

// Deep links to a section that starts collapsed (e.g. the sidebar's
// "New customer" -> /business-parties?relationship=CUSTOMER#register-party)
// open it instead of landing on a hidden element.
function openLinkedSection() {
    if (!window.location.hash) {
        return;
    }
    const target = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));
    if (!target) {
        return;
    }
    if (target.classList.contains('collapse')) {
        bootstrap.Collapse.getOrCreateInstance(target, { toggle: false }).show();
    }
    target.scrollIntoView({ block: 'start' });
}

openLinkedSection();
window.addEventListener('hashchange', openLinkedSection);
