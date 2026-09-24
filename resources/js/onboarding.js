
import './password-toggle';
// Interface text helper: fills :placeholders.
const T = (key, vars = {}) => Object.entries(vars).reduce((t, [k, v]) => t.replace(`:${k}`, v), key);
// Client onboarding: live pricing, coupon check, agent stepper and signature pad.
import * as bootstrap from 'bootstrap';
import SignaturePad from 'signature_pad';

window.bootstrap = bootstrap;

const root = document.documentElement;
const mq = window.matchMedia('(prefers-color-scheme: dark)');
const applyTheme = () => root.setAttribute('data-bs-theme', root.dataset.theme || (mq.matches ? 'dark' : 'light'));
applyTheme();
mq.addEventListener?.('change', applyTheme);

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const money = (cents) => (cents < 0 ? '-' : '') + '$' + (Math.abs(cents) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const pctOf = (cents, pct) => Math.round((cents * pct) / 100);
const setL = (key, text) => $$(`[data-l="${key}"]`).forEach((el) => { el.textContent = text; });

// ---- Step 1: details -------------------------------------------------
const form = $('#detailsForm');
if (form) {
    const pricing = JSON.parse(form.dataset.pricing);
    const agentsInput = $('#agents');
    const help = $('#agHelp');
    const couponInput = $('#coupon');
    const couponMsg = $('#couponMsg');
    let coupon = { code: pricing.discountPercent > 0 && couponInput ? couponInput.value.trim().toUpperCase() : '', percent: pricing.discountPercent };

    const render = () => {
        const entered = Math.max(1, parseInt(agentsInput.value, 10) || 1);
        const billed = Math.max(pricing.minAgents, entered);
        const discount = pctOf(pricing.setup, coupon.percent);
        const impl = pricing.setup - discount;
        const deposit = pctOf(impl, pricing.depositPercent);
        setL('setup', money(pricing.setup));
        setL('discount', money(-discount));
        setL('code', coupon.code);
        setL('implementation', money(impl));
        setL('deposit', money(deposit));
        setL('balance', money(impl - deposit));
        const yearly = ($('input[name="billing"]:checked') || {}).value === 'year';
        const d = 1 - (pricing.annualDiscount || 0) / 100;
        setL('monthly', money(yearly
            ? Math.round(pricing.platform * 12 * d) + billed * Math.round(pricing.perAgent * 12 * d)
            : pricing.platform + billed * pricing.perAgent));
        setL('period', yearly ? T('Yearly') : T('Monthly'));
        setL('periodfee', yearly ? T('Your yearly fee') : T('Your monthly fee'));
        $$('input[name="billing"]').forEach((r) => r.closest('.choice')?.classList.toggle('is-on', r.checked));
        setL('agents', String(billed));
        $$('[data-l-disc]').forEach((el) => el.classList.toggle('d-none', !discount));
        help.textContent = entered < pricing.minAgents
            ? T('Billed at the :min-agent minimum. You can add agents any time.', { min: pricing.minAgents })
            : T('Agents who will use RightAlly. You can change this later.');
    };

    agentsInput.addEventListener('input', render);
    $$('input[name="billing"]').forEach((r) => r.addEventListener('change', render));
    $$('[data-agstep]').forEach((b) => b.addEventListener('click', () => {
        agentsInput.value = Math.min(5000, Math.max(1, (parseInt(agentsInput.value, 10) || 1) + Number(b.dataset.agstep)));
        render();
    }));

    const showCouponMsg = (text, kind) => {
        couponMsg.textContent = text;
        couponMsg.className = 'mt-2 small ' + (kind === 'ok' ? 'coupon-ok' : kind === 'error' ? 'text-danger' : 'text-slate');
        couponInput.classList.toggle('is-invalid', kind === 'error');
    };

    const applyCoupon = async () => {
        const code = couponInput.value.trim().toUpperCase();
        if (!code) { coupon = { code: '', percent: 0 }; showCouponMsg('', 'muted'); render(); return; }
        try {
            const res = await fetch(form.dataset.couponUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').content },
                body: JSON.stringify({ code }),
            });
            if (res.status === 429) { showCouponMsg(T('Too many tries. Wait a minute, then try again.'), 'error'); return; }
            const data = await res.json();
            coupon = data.valid ? { code: data.code, percent: data.percent } : { code: '', percent: 0 };
            showCouponMsg(data.message, data.valid ? 'ok' : 'error');
        } catch {
            showCouponMsg(T('We couldn’t check that code. It will be checked when you continue.'), 'muted');
        }
        render();
    };
    if (couponInput) { // no coupon field on custom quotes
        // × inside the field: remove the code (e.g. an expired one) and go back to standard pricing.
        const couponClear = $('#couponClear');
        const syncClear = () => couponClear?.classList.toggle('d-none', couponInput.value.trim() === '');
        couponInput.addEventListener('input', syncClear);
        couponClear?.addEventListener('click', () => {
            couponInput.value = '';
            couponInput.classList.remove('is-invalid');
            coupon = { code: '', percent: 0 };
            showCouponMsg('', 'muted');
            syncClear();
            render();
            couponInput.focus();
        });
        $('#couponApply').addEventListener('click', applyCoupon);
        couponInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); applyCoupon(); } });
        // Changing the code after applying it: the old discount no longer applies.
        couponInput.addEventListener('input', () => {
            if (coupon.code && couponInput.value.trim().toUpperCase() !== coupon.code) { coupon = { code: '', percent: 0 }; showCouponMsg('', 'muted'); render(); }
        });
    }

    form.addEventListener('submit', () => { $$('button[type="submit"]', form).forEach((b) => { b.disabled = true; }); });
    render();
}

// ---- Step 2: agreement ----------------------------------------------
const doc = $('#agreement-doc');
if (doc) {
    // Give each numbered section heading an anchor for the quick links.
    $$('h3', doc).forEach((h) => { const m = h.textContent.trim().match(/^(\d+)\./); if (m) h.id = `sec-${m[1]}`; });
    $$('.toc [data-sec]').forEach((a) => { if (!$(`#sec-${a.dataset.sec}`)) a.remove(); });
    $$('.toc a').forEach((a) => a.addEventListener('click', (e) => {
        const target = $(a.getAttribute('href'));
        if (!target) return;
        e.preventDefault();
        doc.scrollTo({ top: target.offsetTop - doc.offsetTop - 12, behavior: 'smooth' });
        doc.scrollIntoView({ block: 'nearest' });
    }));
}

const signForm = $('#signForm');
if (signForm) {
    const canvas = $('#pad');
    canvas.style.touchAction = 'none'; // finger drawing must not scroll the page
    const pad = new SignaturePad(canvas, { penColor: '#0839B2', minWidth: 0.8, maxWidth: 2.4 });
    let strokes = [];

    // Phones fire "resize" when the address bar shows or hides; only redraw when the width really changes.
    let lastWidth = 0;
    const resize = () => {
        if (canvas.offsetWidth === lastWidth && lastWidth !== 0) return;
        lastWidth = canvas.offsetWidth;
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        strokes = pad.toData();
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        pad.clear();
        if (strokes.length) pad.fromData(strokes);
    };
    window.addEventListener('resize', resize);
    resize();

    $('#padClear').addEventListener('click', () => pad.clear());
    const typed = $('#typed_name');

    // Keyboard and screen-reader friendly: adopt the typed name as the signature.
    const useTyped = $('#useTyped');
    const syncTyped = () => {
        const on = useTyped?.checked;
        $('#padBox')?.classList.toggle('d-none', !!on);
        $('#padClear')?.classList.toggle('d-none', !!on);
    };
    useTyped?.addEventListener('change', syncTyped);
    syncTyped();
    const typedSignaturePng = async (name) => {
        // Wait briefly for the signature font; fall back to a cursive font if it's slow or blocked.
        try { await Promise.race([document.fonts.load('56px "Mrs Saint Delafield"'), new Promise((r) => setTimeout(r, 1500))]); } catch (e) { /* fallback font */ }
        const c = document.createElement('canvas');
        c.width = 900; c.height = 220;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#0839B2';
        ctx.font = '96px "Mrs Saint Delafield", cursive';
        ctx.textBaseline = 'middle';
        ctx.fillText(name, 30, 115, 840);
        return c.toDataURL('image/png');
    };
    typed.addEventListener('input', () => { $('#typedPreview').textContent = typed.value; });

    let typedReady = false;
    signForm.addEventListener('submit', async (e) => {
        if (typedReady) return; // second pass after rendering the typed signature
        const problems = [];
        const typedMode = !!useTyped?.checked;
        if (!$('#consent').checked) problems.push(T('Tick the box to agree to sign electronically.'));
        if (!typed.value.trim()) problems.push(T('Type your full legal name.'));
        if (!typedMode && pad.isEmpty()) problems.push(T('Draw your signature in the box, or use your typed name instead.'));
        const err = $('#signErr');
        if (problems.length) {
            e.preventDefault();
            err.innerHTML = problems.map((p) => `<div>${p}</div>`).join('');
            err.classList.remove('d-none');
            return;
        }
        err.classList.add('d-none');
        const btn = $('#signBtn');
        btn.disabled = true;
        btn.textContent = T('Signing…');
        if (typedMode) {
            e.preventDefault();
            $('#signatureData').value = await typedSignaturePng(typed.value.trim());
            typedReady = true;
            HTMLFormElement.prototype.submit.call(signForm); // bypasses this handler; checks already done
            return;
        }
        $('#signatureData').value = pad.toDataURL('image/png');
    });
}

// ---- Step 4: payment (Stripe Payment Element) ------------------------
const payForm = document.querySelector('#paymentForm');
if (payForm) {
    const err = document.querySelector('#paymentErr');
    const btn = document.querySelector('#payBtn');
    const showError = (msg) => { err.textContent = msg; err.classList.remove('d-none'); };

    const start = () => {
        if (!window.Stripe) { showError(T('The secure payment form didn’t load. Check your connection and refresh the page.')); return; }
        const stripe = window.Stripe(payForm.dataset.key, { locale: payForm.dataset.locale || 'auto' });
        const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        const elements = stripe.elements({
            clientSecret: payForm.dataset.secret,
            appearance: {
                theme: dark ? 'night' : 'stripe',
                variables: { colorPrimary: '#1457EC', colorText: dark ? '#E7ECF5' : '#041527', borderRadius: '8px', fontFamily: 'Instrument Sans, system-ui, sans-serif' },
            },
        });
        const element = elements.create('payment', {
            layout: { type: 'tabs' },
            defaultValues: { billingDetails: { email: payForm.dataset.email, name: payForm.dataset.name } },
        });
        element.mount('#paymentElement');
        element.on('ready', () => { btn.disabled = false; });

        payForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            err.classList.add('d-none');
            btn.disabled = true;
            const label = btn.textContent;
            btn.textContent = T('Processing…');
            const confirm = payForm.dataset.mode === 'setup' ? stripe.confirmSetup.bind(stripe) : stripe.confirmPayment.bind(stripe);
            const { error } = await confirm({ elements, confirmParams: { return_url: payForm.dataset.return } });
            // Only reached on an immediate error; success redirects to return_url.
            showError(error?.message || T('The payment didn’t go through. Please try again.'));
            btn.disabled = false;
            btn.textContent = label;
        });
    };

    if (window.Stripe) start(); else window.addEventListener('load', start);
}

// Reopen a modal whose form failed validation: <div data-open-modal="#id">
document.querySelectorAll('[data-open-modal]').forEach((el) => {
    const modal = document.querySelector(el.dataset.openModal);
    if (modal) bootstrap.Modal.getOrCreateInstance(modal).show();
});
