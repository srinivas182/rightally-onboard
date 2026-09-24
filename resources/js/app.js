import * as bootstrap from 'bootstrap';
import './password-toggle';
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

// Reopen the modal whose form failed validation: <div data-open-modal="#id">
document.querySelectorAll('[data-open-modal]').forEach((el) => {
    const modal = document.querySelector(el.dataset.openModal);
    if (modal) bootstrap.Modal.getOrCreateInstance(modal).show();
});

// "Never expires" switch disables the expiry date in the same form.
document.addEventListener('change', (e) => {
    if (!e.target.matches('[data-never]')) return;
    const date = e.target.form?.querySelector('[data-expiry]');
    if (date) { date.disabled = e.target.checked; if (e.target.checked) date.value = ''; }
});

// Copy to clipboard: <button data-copy="text">
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    const original = btn.textContent;
    try {
        await navigator.clipboard.writeText(btn.dataset.copy);
        btn.textContent = 'Copied';
    } catch {
        window.prompt('Copy this link:', btn.dataset.copy);
    }
    setTimeout(() => { btn.textContent = original; }, 1500);
});

// Insert text at the cursor of a textarea: <button data-insert="text" data-target="#id">
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-insert]');
    if (!btn) return;
    const ta = document.querySelector(btn.dataset.target);
    if (!ta) return;
    const start = ta.selectionStart ?? ta.value.length;
    ta.value = ta.value.slice(0, start) + btn.dataset.insert + ta.value.slice(ta.selectionEnd ?? start);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = start + btn.dataset.insert.length;
});

// Dashboard revenue chart (Chart.js is loaded only on pages that have one).
const revChart = document.getElementById('revChart');
if (revChart) {
    import('chart.js/auto').then(({ default: Chart }) => {
        const data = JSON.parse(revChart.dataset.chart);
        const css = getComputedStyle(document.documentElement);
        const fmt = (v) => '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        new Chart(revChart, {
            type: 'bar',
            data: { labels: data.labels, datasets: [{ label: 'Revenue', data: data.values, backgroundColor: '#1457EC', borderRadius: 6, maxBarThickness: 36 }] },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (x) => fmt(x.parsed.y) } } },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: (v) => '$' + (v >= 1000 ? v / 1000 + 'k' : v), color: css.getPropertyValue('--ra-slate') }, grid: { color: css.getPropertyValue('--ra-line') } },
                    x: { ticks: { color: css.getPropertyValue('--ra-slate') }, grid: { display: false } },
                },
            },
        });
    });
}
