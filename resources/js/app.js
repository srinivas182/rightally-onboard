import * as bootstrap from 'bootstrap';
window.bootstrap = bootstrap;

// Follow the viewer's light/dark preference for Bootstrap components.
const root = document.documentElement;
const mq = window.matchMedia('(prefers-color-scheme: dark)');
const applyTheme = () => root.setAttribute('data-bs-theme', root.dataset.theme || (mq.matches ? 'dark' : 'light'));
applyTheme();
mq.addEventListener?.('change', applyTheme);

// Keep the active settings/detail tab when the page reloads after a save.
document.querySelectorAll('[data-remember-tab]').forEach((nav) => {
    const key = 'tab:' + nav.dataset.rememberTab;
    const hash = window.location.hash || sessionStorageSafeGet(key);
    if (hash) {
        const trigger = nav.querySelector(`[href="${hash}"]`);
        if (trigger) bootstrap.Tab.getOrCreateInstance(trigger).show();
    }
    nav.querySelectorAll('[data-bs-toggle="tab"]').forEach((a) =>
        a.addEventListener('shown.bs.tab', () => {
            history.replaceState(null, '', a.getAttribute('href'));
            try { sessionStorage.setItem(key, a.getAttribute('href')); } catch (e) { /* storage unavailable */ }
        }),
    );
});

function sessionStorageSafeGet(key) {
    try { return sessionStorage.getItem(key); } catch (e) { return null; }
}

// Confirm destructive actions: <button data-confirm="Are you sure?">
document.addEventListener('submit', (e) => {
    const btn = e.submitter;
    if (btn?.dataset.confirm && !window.confirm(btn.dataset.confirm)) e.preventDefault();
});
