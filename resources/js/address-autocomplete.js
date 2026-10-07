// US address suggestions for "Street address" using Google Places (New). Only loaded when a
// Google Maps key is set in Settings; if Google can't load, people simply type the address.
const $ = (s, r = document) => r.querySelector(s);

function loadGoogle(key) {
    if (window.google?.maps?.importLibrary) return Promise.resolve();
    return new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&v=weekly&loading=async&libraries=places`;
        s.async = true;
        const nonce = document.querySelector('meta[name="csp-nonce"]')?.content;
        if (nonce) s.nonce = nonce;
        s.onload = () => (window.google?.maps?.importLibrary ? resolve() : reject(new Error('maps')));
        s.onerror = reject;
        document.head.appendChild(s);
    });
}

export async function initAddressAutocomplete(key) {
    const street = $('#street');
    if (!street) return;
    let places;
    try {
        await loadGoogle(key);
        places = await window.google.maps.importLibrary('places');
    } catch {
        return; // typing still works
    }
    const { AutocompleteSuggestion, AutocompleteSessionToken } = places;
    if (!AutocompleteSuggestion) return;

    const list = document.createElement('ul');
    list.className = 'addr-suggest list-unstyled d-none';
    list.id = 'streetSuggest';
    list.setAttribute('role', 'listbox');
    street.parentNode.style.position = 'relative';
    street.after(list);
    street.setAttribute('role', 'combobox');
    street.setAttribute('aria-autocomplete', 'list');
    street.setAttribute('aria-controls', 'streetSuggest');
    street.setAttribute('aria-expanded', 'false');
    street.setAttribute('autocomplete', 'off');

    let token = new AutocompleteSessionToken();
    let items = [];
    let active = -1;
    let timer;

    const close = () => { list.classList.add('d-none'); street.setAttribute('aria-expanded', 'false'); active = -1; };
    const highlight = (i) => {
        active = i;
        [...list.children].forEach((li, n) => li.setAttribute('aria-selected', n === i ? 'true' : 'false'));
        if (i >= 0) street.setAttribute('aria-activedescendant', `addr-${i}`);
    };

    const fill = async (prediction) => {
        close();
        try {
            const place = prediction.toPlace();
            await place.fetchFields({ fields: ['addressComponents'] });
            const get = (type, short = false) => {
                const c = (place.addressComponents || []).find((x) => x.types.includes(type));
                return c ? (short ? c.shortText : c.longText) : '';
            };
            street.value = [get('street_number'), get('route')].filter(Boolean).join(' ') || prediction.mainText?.text || street.value;
            const city = get('locality') || get('sublocality') || get('postal_town') || get('administrative_area_level_3');
            if (city) $('#city').value = city;
            const state = get('administrative_area_level_1', true);
            if (state && $('#state_code')) $('#state_code').value = state;
            const zip = get('postal_code');
            if (zip) $('#zip').value = zip;
        } catch { /* keep what they typed */ }
        token = new AutocompleteSessionToken(); // one billing session per chosen address
    };

    street.addEventListener('input', () => {
        clearTimeout(timer);
        const q = street.value.trim();
        if (q.length < 4) { close(); return; }
        timer = setTimeout(async () => {
            try {
                const { suggestions } = await AutocompleteSuggestion.fetchAutocompleteSuggestions({
                    input: q, sessionToken: token, includedRegionCodes: ['us'], includedPrimaryTypes: ['street_address', 'premise', 'subpremise'],
                });
                items = (suggestions || []).map((s) => s.placePrediction).filter(Boolean).slice(0, 5);
            } catch { items = []; }
            list.innerHTML = '';
            items.forEach((p, i) => {
                const li = document.createElement('li');
                li.id = `addr-${i}`;
                li.setAttribute('role', 'option');
                li.textContent = p.text?.text || '';
                li.addEventListener('mousedown', (e) => { e.preventDefault(); fill(p); });
                list.appendChild(li);
            });
            if (items.length) { list.classList.remove('d-none'); street.setAttribute('aria-expanded', 'true'); } else { close(); }
        }, 250);
    });
    street.addEventListener('keydown', (e) => {
        if (list.classList.contains('d-none')) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); highlight(Math.min(items.length - 1, active + 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(Math.max(0, active - 1)); }
        else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); fill(items[active]); }
        else if (e.key === 'Escape') { close(); }
    });
    street.addEventListener('blur', () => setTimeout(close, 150));
}
