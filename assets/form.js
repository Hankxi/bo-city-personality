(function(){
  const settings = window.boCPData || {};

  const readQueryDebug = () => {
    try {
      const search = window.location && window.location.search;
      if (!search) return null;
      const params = new URLSearchParams(search);
      if (!params.has('bo_cp_debug')) return null;
      return params.get('bo_cp_debug');
    } catch (err) {
      return null;
    }
  };

  const readStorageDebug = () => {
    try {
      const value = window.localStorage && window.localStorage.getItem('boCPDebug');
      return value === null ? null : value;
    } catch (err) {
      return null;
    }
  };

  const queryDebug = readQueryDebug();
  let storageDebug = readStorageDebug();

  if (queryDebug !== null) {
    const shouldEnable = queryDebug !== '0' && queryDebug !== 'false';
    try {
      if (window.localStorage) {
        if (shouldEnable) {
          window.localStorage.setItem('boCPDebug', '1');
        } else {
          window.localStorage.removeItem('boCPDebug');
        }
      }
    } catch (err) {
      // ignore storage errors
    }
    storageDebug = shouldEnable ? '1' : null;
  }

  const combinedDebug = () => {
    if (storageDebug === '1') {
      return true;
    }
    if (storageDebug === '0') {
      return false;
    }
    if (typeof settings.debug !== 'undefined') {
      return Boolean(settings.debug);
    }
    return false;
  };

  const debugEnabled = combinedDebug();
  const logDebug = (...args) => {
    if (!debugEnabled || typeof console === 'undefined') {
      return;
    }
    const method = console.debug ? 'debug' : 'log';
    try {
      console[method].apply(console, ['[bo-cp]'].concat(args));
    } catch (err) {
      console.log('[bo-cp]', ...args);
    }
  };

  const announceDebugState = () => {
    if (!debugEnabled) {
      return;
    }
    const redacted = Object.assign({}, settings);
    if (redacted.placesKey) {
      redacted.placesKey = `${String(redacted.placesKey).slice(0, 6)}…`;
    }
    logDebug('Debug mode enabled', redacted);
  };

  announceDebugState();
  const createSessionToken = () => {
    try {
      if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
      }
    } catch (err) {
      // ignore crypto errors
    }
    const template = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx';
    return template.replace(/[xy]/g, (char) => {
      const rand = Math.random() * 16 | 0;
      const value = char === 'x' ? rand : (rand & 0x3) | 0x8;
      return value.toString(16);
    });
  };
  const normalizeRestRoot = (value) => {
    if (!value) return '/wp-json/bo/v1';
    try {
      const str = value.toString();
      if (!str) return '/wp-json/bo/v1';
      return str.replace(/\/?$/, '');
    } catch (err) {
      return '/wp-json/bo/v1';
    }
  };
  const restRoot = normalizeRestRoot(settings.restRoot);
  const restUrl = (path='') => {
    const segment = path.startsWith('/') ? path : `/${path}`;
    return `${restRoot}${segment}`;
  };

  const $ = (s, r=document) => r.querySelector(s);

  const canonKey = (value='') => {
    return (value || '')
      .toString()
      .toLowerCase()
      .replace(/–/g, '-')
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '') || 'persona';
  };

  const escapeHtml = (str='') => {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  };

  const ensureState = () => {
    if (!window.__boCP) {
      window.__boCP = {};
    }
    if (!window.__boCP.sectionsCache) {
      window.__boCP.sectionsCache = {};
    }
    window.__boCP.debug = debugEnabled;
    return window.__boCP;
  };

  try {
    window.boCPDebug = {
      enable() {
        try {
          if (window.localStorage) {
            window.localStorage.setItem('boCPDebug', '1');
          }
        } catch (err) {
          // ignore
        }
        if (!debugEnabled) {
          console.log('[bo-cp] Debug enabled via window.boCPDebug.enable(); reload to capture logs.');
        }
      },
      disable() {
        try {
          if (window.localStorage) {
            window.localStorage.removeItem('boCPDebug');
          }
        } catch (err) {
          // ignore
        }
        if (debugEnabled) {
          console.log('[bo-cp] Debug disabled via window.boCPDebug.disable(); reload to stop logging.');
        }
      },
      state() {
        return Boolean(combinedDebug());
      }
    };
  } catch (err) {
    // ignore inability to expose helper
  }

  const normalizeLang = (value='') => {
    const str = (value || '').toString().trim().toLowerCase();
    if (!str) return '';
    const match = str.match(/^[a-z]{2,3}/);
    return match ? match[0] : str;
  };

  const detectLang = () => {
    const state = ensureState();
    if (state.lang) {
      const normalized = normalizeLang(state.lang);
      if (normalized) {
        logDebug('detectLang from state', normalized);
        return normalized;
      }
    }
    const configured = normalizeLang(settings.lang || '');
    if (configured) {
      logDebug('detectLang from settings', configured);
      return configured;
    }
    const html = normalizeLang(document.documentElement && document.documentElement.lang);
    if (html) {
      logDebug('detectLang from <html>', html);
      return html;
    }
    const body = normalizeLang(document.body && document.body.getAttribute('lang'));
    if (body) {
      logDebug('detectLang from <body>', body);
      return body;
    }
    const hidden = document.querySelector('#bo-cp-form input[name="lang"]');
    if (hidden && hidden.value) {
      const normalized = normalizeLang(hidden.value);
      if (normalized) {
        logDebug('detectLang from hidden field', normalized);
        return normalized;
      }
    }
    logDebug('detectLang defaulting to en');
    return 'en';
  };

  let googlePlacesLoader = null;
  const ensureGooglePlaces = () => {
    logDebug('ensureGooglePlaces invoked');
    if (!settings.placesKey) {
      return Promise.reject(new Error('Missing Google Places key'));
    }
    if (window.google && window.google.maps && window.google.maps.places) {
      logDebug('Google Places already loaded');
      return Promise.resolve(window.google.maps);
    }
    if (googlePlacesLoader) {
      return googlePlacesLoader;
    }
    googlePlacesLoader = new Promise((resolve, reject) => {
      const head = document.head || document.getElementsByTagName('head')[0];
      const script = document.createElement('script');
      const params = new URLSearchParams({
        key: settings.placesKey,
        libraries: 'places',
        language: detectLang() || 'en',
      });
      script.src = `https://maps.googleapis.com/maps/api/js?${params.toString()}`;
      script.async = true;
      script.defer = true;
      script.onerror = () => {
        googlePlacesLoader = null;
        reject(new Error('Failed to load Google Maps script'));
      };
      script.onload = () => {
        if (window.google && window.google.maps && window.google.maps.places) {
          logDebug('Google Places script loaded successfully');
          resolve(window.google.maps);
        } else {
          logDebug('Google Places script loaded but API unavailable');
          googlePlacesLoader = null;
          reject(new Error('Google Places library unavailable'));
        }
      };
      head.appendChild(script);
    });
    return googlePlacesLoader;
  };

  const googleAutocompleteCache = {};

  const fetchSuggestionsViaGoogle = async (query, options = {}) => {
    const trimmed = (query || '').trim();
    if (trimmed.length < 2) {
      logDebug('Google suggestions skipped, query too short', trimmed);
      return [];
    }
    const lang = (options && options.lang) || detectLang() || 'en';
    const cacheKey = `${trimmed}|${lang}`;
    if (googleAutocompleteCache[cacheKey]) {
      logDebug('Using cached Google suggestions', cacheKey, googleAutocompleteCache[cacheKey]);
      return googleAutocompleteCache[cacheKey];
    }
    try {
      logDebug('Fetching Google suggestions', trimmed);
      const maps = await ensureGooglePlaces();
      if (!maps || !maps.places) {
        logDebug('Google maps places unavailable after ensure');
        return [];
      }
      const service = fetchSuggestionsViaGoogle._service || new maps.places.AutocompleteService();
      fetchSuggestionsViaGoogle._service = service;
      const request = {
        input: trimmed,
        language: lang,
      };
      if (options && typeof options.getSessionToken === 'function') {
        try {
          const providedToken = options.getSessionToken(maps);
          if (providedToken) {
            request.sessionToken = providedToken;
          }
        } catch (tokenErr) {
          logDebug('Google session token provider error', tokenErr);
        }
      }
      const predictions = await new Promise((resolve, reject) => {
        service.getPlacePredictions(request, (items, status) => {
          if (status === maps.places.PlacesServiceStatus.OK && Array.isArray(items)) {
            logDebug('Google suggestions returned', items.length);
            resolve(items);
            return;
          }
          if (status === maps.places.PlacesServiceStatus.ZERO_RESULTS) {
            logDebug('Google suggestions zero results');
            resolve([]);
            return;
          }
          logDebug('Google suggestions status', status);
          reject(new Error(`Google Places status ${status}`));
        });
      });
      const normalized = (Array.isArray(predictions) ? predictions : []).map((prediction) => ({
        place_id: prediction.place_id || '',
        description: prediction.description || '',
        matched_substrings: prediction.matched_substrings || [],
        terms: prediction.terms || [],
        types: prediction.types || [],
      })).filter((item) => item.description);
      googleAutocompleteCache[cacheKey] = normalized;
      logDebug('Cached Google suggestions', cacheKey, normalized.length);
      return normalized;
    } catch (err) {
      console.error('Google Places fallback failed', err);
      logDebug('Google suggestions error', err);
      return [];
    }
  };

  const parseGoogleAddress = (components) => {
    let city = '';
    let country = '';
    let countryCode = '';
    if (Array.isArray(components)) {
      components.forEach((comp) => {
        if (!comp || typeof comp !== 'object') return;
        const types = Array.isArray(comp.types) ? comp.types : [];
        if (types.includes('country')) {
          country = comp.long_name || comp.short_name || country;
          countryCode = comp.short_name || countryCode;
        }
        if (!city && (types.includes('locality') || types.includes('administrative_area_level_1') || types.includes('administrative_area_level_2'))) {
          city = comp.long_name || comp.short_name || city;
        }
      });
    }
    return { city, country, country_code: countryCode };
  };

  const fetchPlaceDetailsViaGoogle = async (placeId, options = {}) => {
    if (!placeId || !settings.placesKey) {
      logDebug('Skipping Google place details', { placeId, hasKey: Boolean(settings.placesKey) });
      return null;
    }
    try {
      logDebug('Fetching Google place details', placeId);
      const maps = await ensureGooglePlaces();
      if (!maps || !maps.places) {
        logDebug('Google maps places unavailable for details');
        return null;
      }
      if (!fetchPlaceDetailsViaGoogle._container) {
        const div = document.createElement('div');
        div.style.display = 'none';
        document.body.appendChild(div);
        fetchPlaceDetailsViaGoogle._container = div;
      }
      const service = fetchPlaceDetailsViaGoogle._service || new maps.places.PlacesService(fetchPlaceDetailsViaGoogle._container);
      fetchPlaceDetailsViaGoogle._service = service;
      const request = {
        placeId,
        language: 'en',
        fields: ['address_component', 'geometry.location', 'formatted_address', 'name', 'place_id'],
      };
      if (options && typeof options.getSessionToken === 'function') {
        try {
          const providedToken = options.getSessionToken(maps);
          if (providedToken) {
            request.sessionToken = providedToken;
          }
        } catch (tokenErr) {
          logDebug('Google details session token error', tokenErr);
        }
      }
      const result = await new Promise((resolve, reject) => {
        service.getDetails(request, (place, status) => {
          if (status === maps.places.PlacesServiceStatus.OK && place) {
            logDebug('Google place details returned');
            resolve(place);
            return;
          }
          if (status === maps.places.PlacesServiceStatus.ZERO_RESULTS) {
            logDebug('Google place details zero results');
            resolve(null);
            return;
          }
          logDebug('Google place details status', status);
          reject(new Error(`Google Place details status ${status}`));
        });
      });
      if (!result) {
        logDebug('Google place details empty result');
        return null;
      }
      const coords = result.geometry && result.geometry.location;
      const parsed = parseGoogleAddress(result.address_components);
      return {
        place_id: result.place_id || placeId,
        city: parsed.city || '',
        country: parsed.country || '',
        country_code: parsed.country_code || '',
        formatted_address: result.formatted_address || '',
        name: result.name || '',
        lat: coords && typeof coords.lat === 'function' ? coords.lat() : '',
        lng: coords && typeof coords.lng === 'function' ? coords.lng() : '',
      };
    } catch (err) {
      console.error('Google details fallback failed', err);
      logDebug('Google place details error', err);
      return null;
    }
  };

  const fetchSections = async (persona, lang, keys) => {
    const state = ensureState();
    if (!Array.isArray(keys) || !keys.length) {
      logDebug('fetchSections called without keys', { persona, lang });
      return {};
    }

    const canonPersona = canonKey(persona);
    const normalizedLang = (lang || 'en').toLowerCase();

    logDebug('fetchSections start', { persona: canonPersona, lang: normalizedLang, keys });

    if (!state.sectionsCache[canonPersona]) {
      state.sectionsCache[canonPersona] = {};
    }
    if (!state.sectionsCache[canonPersona][normalizedLang]) {
      state.sectionsCache[canonPersona][normalizedLang] = {};
    }

    const langCache = state.sectionsCache[canonPersona][normalizedLang];

    const missing = keys.filter((k) => !(k in langCache));
    if (missing.length) {
      logDebug('fetchSections requesting missing keys', missing);
      let fetched = false;
      try {
        const params = new URLSearchParams({
          persona: canonPersona,
          lang: normalizedLang,
          keys: missing.join(','),
        });
        const res = await fetch(`${restUrl('sections')}?${params.toString()}`);
        if (res.ok) {
          const payload = await res.json();
          if (payload && payload.sections && typeof payload.sections === 'object') {
            Object.entries(payload.sections).forEach(([key, value]) => {
              langCache[key] = value || { title: '', content_html: '' };
            });
            logDebug('fetchSections populated cache', Object.keys(payload.sections || {}));
          }
          fetched = true;
        } else {
          console.error('Failed to fetch sections', res.status, res.statusText);
          logDebug('fetchSections fetch error status', res.status);
        }
      } catch (err) {
        console.error('Failed to fetch sections', err);
        logDebug('fetchSections fetch exception', err);
      }
      if (fetched) {
        missing.forEach((key) => {
          if (!(key in langCache)) {
            langCache[key] = { title: '', content_html: '' };
          }
        });
      }
    }

    const out = {};
    keys.forEach((key) => {
      out[key] = langCache[key] || { title: '', content_html: '' };
    });
    logDebug('fetchSections result ready', { persona: canonPersona, lang: normalizedLang, keys });
    return out;
  };

  const renderSection = (el, row, lang, persona, showTitle) => {
    if (!el) return;
    logDebug('renderSection', { element: el.dataset.section || el.id, lang, persona, hasContent: Boolean(row && row.content_html) });
    const content = row && row.content_html ? String(row.content_html) : '';
    const title = row && row.title ? String(row.title) : '';
    let html = '';
    if (showTitle && title) {
      html += `<h3 class="bo-cp-section-title">${escapeHtml(title)}</h3>`;
    }
    if (content) {
      html += content;
    }
    el.innerHTML = html;
    el.dataset.lang = lang || '';
    el.dataset.persona = persona || '';
    el.classList.add('bo-cp-section-loaded');
  };

  const updateDynamicSections = async (options = {}) => {
    const placeholders = Array.from(document.querySelectorAll('[data-bo-cp-dynamic="1"]'));
    if (!placeholders.length) {
      logDebug('updateDynamicSections skipped, no placeholders');
      return;
    }

    const persona = options.persona_key || options.name || '';
    if (!persona) {
      logDebug('updateDynamicSections skipped, missing persona', options);
      return;
    }

    const lang = (options.lang || 'en').toLowerCase();
    const keys = Array.from(new Set(placeholders.map((el) => el.dataset.section).filter(Boolean)));
    if (!keys.length) {
      logDebug('updateDynamicSections skipped, no keys found');
      return;
    }

    logDebug('updateDynamicSections start', { persona, lang, keys });
    const primary = await fetchSections(persona, lang, keys);

    const fallbackPlan = new Map();
    placeholders.forEach((el) => {
      const key = el.dataset.section;
      if (!key) return;
      const row = primary[key];
      const fallback = (el.dataset.fallback || '').toLowerCase();
      if ((!row || !row.content_html) && fallback && fallback !== lang) {
        if (!fallbackPlan.has(fallback)) {
          fallbackPlan.set(fallback, new Set());
        }
        fallbackPlan.get(fallback).add(key);
      }
    });

    const fallbackResults = {};
    for (const [fbLang, fbKeysSet] of fallbackPlan.entries()) {
      const fbKeys = Array.from(fbKeysSet.values());
      fallbackResults[fbLang] = await fetchSections(persona, fbLang, fbKeys);
    }

    placeholders.forEach((el) => {
      const key = el.dataset.section;
      if (!key) return;
      const showTitle = (el.dataset.showTitle || 'yes').toLowerCase() !== 'no';
      let row = primary[key];
      let usedLang = lang;
      if ((!row || !row.content_html)) {
        const fallback = (el.dataset.fallback || '').toLowerCase();
        if (fallback && fallback !== lang && fallbackResults[fallback]) {
          row = fallbackResults[fallback][key] || row;
          if (row && row.content_html) {
            usedLang = fallback;
          }
        }
      }
      if (!row) {
        row = { title: '', content_html: '' };
      }
      logDebug('updateDynamicSections render', { key, lang: usedLang, hasContent: Boolean(row.content_html) });
      renderSection(el, row, usedLang, canonKey(persona), showTitle);
    });
  };

  const placeDetailsCache = new Map();

  const initBirthDateToggle = () => {
    const form = document.querySelector('#bo-cp-form');
    if (!form) {
      logDebug('initBirthDateToggle skipped, form not found');
      return;
    }
    const input = form.querySelector('[data-date-input]');
    const toggle = form.querySelector('[data-date-toggle]');
    if (!input || !toggle) {
      logDebug('initBirthDateToggle skipped, missing elements', { hasInput: Boolean(input), hasToggle: Boolean(toggle) });
      return;
    }

    const manualLabel = toggle.dataset.manualLabel || 'Manual Entry';
    const pickerLabel = toggle.dataset.pickerLabel || 'Use Date Picker';
    let manualMode = false;

    logDebug('initBirthDateToggle ready', { defaultValue: input.value });

    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      manualMode = !manualMode;
      logDebug('Birth date mode toggled', { manualMode });
      if (manualMode) {
        input.setAttribute('type', 'text');
        if (!input.placeholder) {
          input.setAttribute('data-original-placeholder', input.getAttribute('placeholder') || '');
        }
        input.placeholder = 'YYYY-MM-DD';
        toggle.textContent = pickerLabel;
      } else {
        input.setAttribute('type', 'date');
        const original = input.getAttribute('data-original-placeholder') || '';
        if (original) {
          input.placeholder = original;
        } else {
          input.removeAttribute('placeholder');
        }
        if (!input.value) {
          input.value = '2000-01-01';
        }
        toggle.textContent = manualLabel;
      }
      input.focus();
    });
  };

  const fetchWithTimeout = async (input, init = {}, timeoutMs = 8000) => {
    logDebug('fetchWithTimeout', { url: typeof input === 'string' ? input : 'Request', timeoutMs });
    if (typeof AbortController === 'undefined') {
      return fetch(input, init);
    }
    const controller = new AbortController();
    const timer = setTimeout(() => {
      controller.abort();
    }, timeoutMs);
    try {
      return await fetch(input, Object.assign({}, init, { signal: controller.signal }));
    } finally {
      clearTimeout(timer);
    }
  };

  const fetchPlaceDetails = async (placeId, options = {}) => {
    if (!placeId) return null;
    if (placeDetailsCache.has(placeId)) {
      logDebug('Using cached place details', placeId);
      return placeDetailsCache.get(placeId);
    }

    const attemptRestFetch = async (langAttempt) => {
      const params = new URLSearchParams({ place_id: placeId });
      if (langAttempt) {
        params.set('lang', langAttempt);
      }
      const langLabel = langAttempt || '(default)';
      logDebug('Fetching place details via REST', { placeId, lang: langLabel });
      const res = await fetchWithTimeout(`${restUrl('place-details')}?${params.toString()}`, {
        credentials: 'same-origin'
      }, 7000);
      const text = await res.text();
      let payload = null;
      if (text) {
        try {
          payload = JSON.parse(text);
        } catch (jsonErr) {
          logDebug('REST place details JSON parse error', jsonErr);
        }
      }
      if (!res.ok) {
        logDebug('REST place details HTTP error', { status: res.status, lang: langLabel });
        const error = new Error(`HTTP ${res.status}`);
        error.status = res.status;
        if (payload && payload.code) {
          error.code = payload.code;
        }
        throw error;
      }
      if (!payload) {
        const error = new Error('Invalid payload');
        error.status = res.status;
        throw error;
      }
      logDebug('REST place details payload', payload);
      return payload;
    };

    const attemptedLangs = [];
    const detectedLang = normalizeLang(detectLang());
    if (detectedLang) {
      attemptedLangs.push(detectedLang);
    }
    if (!attemptedLangs.includes('en')) {
      attemptedLangs.push('en');
    }
    if (!attemptedLangs.includes('')) {
      attemptedLangs.push('');
    }

    let restPayload = null;
    let restError = null;
    for (const langAttempt of attemptedLangs) {
      try {
        restPayload = await attemptRestFetch(langAttempt);
        break;
      } catch (err) {
        restError = err;
        const errorMeta = {
          lang: langAttempt || '(default)',
          status: err && err.status ? err.status : null,
          code: err && err.code ? err.code : null,
        };
        logDebug('REST place details attempt failed', errorMeta);
        if (!err || ((err.code !== 'rest_no_route') && (err.status !== 404))) {
          break;
        }
      }
    }

    if (restPayload) {
      if ((!restPayload.city || !restPayload.country) && settings.placesKey) {
        const supplement = await fetchPlaceDetailsViaGoogle(placeId, options);
        if (supplement) {
          const merged = Object.assign({}, restPayload, {
            city: restPayload.city || supplement.city || '',
            country: restPayload.country || supplement.country || '',
            place_id: restPayload.place_id || supplement.place_id || placeId,
          });
          logDebug('Merged REST place details with Google fallback', merged);
          placeDetailsCache.set(placeId, merged);
          return merged;
        }
      }
      placeDetailsCache.set(placeId, restPayload);
      logDebug('Cached REST place details', placeId);
      return restPayload;
    }

    const finalError = restError || new Error('Unknown REST error');
    console.error('Failed to fetch place details', finalError);
    if (settings.placesKey) {
      const fallback = await fetchPlaceDetailsViaGoogle(placeId, options);
      if (fallback) {
        placeDetailsCache.set(placeId, fallback);
        logDebug('Using Google fallback for place details', fallback);
        return fallback;
      }
    }
    logDebug('Place details fetch failed completely', placeId);
    return null;
  };

  const initLocationPicker = () => {
    const form = document.querySelector('#bo-cp-form');
    if (!form) {
      logDebug('initLocationPicker skipped, form not found');
      return;
    }
    const wrapper = form.querySelector('[data-location-wrapper]');
    if (!wrapper) {
      logDebug('initLocationPicker skipped, wrapper not found');
      return;
    }
    const input = wrapper.querySelector('[data-location-input]');
    const suggestionsEl = wrapper.querySelector('[data-location-suggestions]');
    const statusEl = wrapper.querySelector('[data-location-status]');
    const clearBtn = wrapper.querySelector('[data-location-clear]');
    const cityField = form.querySelector('input[name="city"]');
    const countryField = form.querySelector('input[name="country"]');
    const placeField = form.querySelector('input[name="place_id"]');
    if (!input || !suggestionsEl || !statusEl || !cityField || !countryField || !placeField) {
      logDebug('initLocationPicker missing elements', { hasInput: Boolean(input), hasSuggestions: Boolean(suggestionsEl), hasStatus: Boolean(statusEl), hasCity: Boolean(cityField), hasCountry: Boolean(countryField), hasPlace: Boolean(placeField) });
      return;
    }

    logDebug('initLocationPicker ready');

    let debounceTimer = null;
    let requestToken = 0;
    let currentSuggestions = [];
    let activeIndex = -1;
    let lastQuery = '';
    let serverSessionToken = '';
    let googleSessionToken = null;

    // -------------------------------
    // PORTAL OVERLAY: mount to <body>
    // -------------------------------
    let overlayMounted = false;

    function mountOverlayToBody() {
      if (overlayMounted) return;
      // 用 fixed 脱离父容器裁切，并提到 <body>
      suggestionsEl.style.position = 'fixed';
      suggestionsEl.style.maxHeight = suggestionsEl.style.maxHeight || '320px';
      suggestionsEl.style.overflowY = suggestionsEl.style.overflowY || 'auto';
      suggestionsEl.style.zIndex = '1000000';
      suggestionsEl.style.margin = '0';
      // 移到 body
      document.body.appendChild(suggestionsEl);
      overlayMounted = true;
      logDebug('Overlay mounted to <body>');
    }

    function updateOverlayPosition() {
      if (!overlayMounted || suggestionsEl.hidden) return;
      const r = input.getBoundingClientRect();
      // 与输入框左对齐，宽度等于输入框可见宽度，下边距间隔 6px
      suggestionsEl.style.left = `${Math.round(r.left)}px`;
      suggestionsEl.style.top  = `${Math.round(r.bottom + 6)}px`;
      suggestionsEl.style.width = `${Math.round(r.width)}px`;
    }

    function showOverlay() {
      mountOverlayToBody();
      suggestionsEl.hidden = false;
      updateOverlayPosition();
      // 滚动/缩放/输入框尺寸变更时更新位置
      window.addEventListener('scroll', updateOverlayPosition, true);
      window.addEventListener('resize', updateOverlayPosition, true);
    }

    function hideOverlay() {
      suggestionsEl.hidden = true;
      window.removeEventListener('scroll', updateOverlayPosition, true);
      window.removeEventListener('resize', updateOverlayPosition, true);
    }
    // -------------------------------

    const setStatus = (message = '', state = '') => {
      if (!statusEl) return;
      logDebug('Location status update', { message, state });
      if (message) {
        statusEl.textContent = message;
        statusEl.hidden = false;
        if (state) {
          statusEl.dataset.state = state;
        } else {
          delete statusEl.dataset.state;
        }
      } else {
        statusEl.textContent = '';
        statusEl.hidden = true;
        delete statusEl.dataset.state;
      }
    };

    const hideSuggestions = () => {
      logDebug('Hiding suggestions');
      suggestionsEl.innerHTML = '';
      hideOverlay(); // ← 替换为 Portal 版本隐藏
      activeIndex = -1;
      input.setAttribute('aria-expanded', 'false');
    };

    const resetHiddenFields = () => {
      logDebug('Resetting hidden location fields');
      cityField.value = '';
      countryField.value = '';
      placeField.value = '';
    };

    const ensureServerSessionToken = () => {
      if (!serverSessionToken) {
        serverSessionToken = createSessionToken();
        logDebug('Created server session token', serverSessionToken.slice(0, 8));
      }
      return serverSessionToken;
    };

    const provideGoogleSessionToken = (maps) => {
      if (!maps || !maps.places || !maps.places.AutocompleteSessionToken) {
        return null;
      }
      if (!googleSessionToken) {
        googleSessionToken = new maps.places.AutocompleteSessionToken();
        logDebug('Created Google session token');
      }
      return googleSessionToken;
    };

    const resetSessionContext = () => {
      if (serverSessionToken || googleSessionToken) {
        logDebug('Reset suggestion session context');
      }
      serverSessionToken = '';
      googleSessionToken = null;
    };

    const dedupeSuggestions = (items, limit = 8) => {
      logDebug('dedupeSuggestions input', { count: Array.isArray(items) ? items.length : 0, limit });
      const seen = new Set();
      const out = [];
      items.forEach((item) => {
        if (!item || typeof item !== 'object') {
          return;
        }
        const description = (item.description || '').toString().trim();
        if (!description) {
          return;
        }
        const placeId = (item.place_id || '').toString();
        const key = `${placeId}|${description.toLowerCase()}`;
        if (seen.has(key)) {
          return;
        }
        seen.add(key);
        out.push({
          place_id: placeId,
          description,
          matched_substrings: Array.isArray(item.matched_substrings) ? item.matched_substrings : [],
          terms: Array.isArray(item.terms) ? item.terms : [],
          types: Array.isArray(item.types) ? item.types : [],
        });
      });
      if (typeof limit === 'number' && limit > 0) {
        const sliced = out.slice(0, limit);
        logDebug('dedupeSuggestions output', { count: sliced.length });
        return sliced;
      }
      logDebug('dedupeSuggestions output', { count: out.length });
      return out;
    };

    const renderSuggestions = (items) => {
      logDebug('Rendering suggestions', { count: items.length });
      suggestionsEl.innerHTML = '';
      items.forEach((item, index) => {
        const li = document.createElement('li');
        li.className = 'bo-cp-location__item';
        li.setAttribute('role', 'presentation');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'bo-cp-location__suggestion';
        btn.dataset.index = String(index);
        btn.dataset.placeId = item.place_id || '';
        btn.setAttribute('role', 'option');
        const optionId = `${suggestionsEl.id || 'bo-cp-location-option'}-${index}`;
        btn.id = optionId;
        btn.textContent = item.description || '';
        li.appendChild(btn);
        suggestionsEl.appendChild(li);
      });
      if (items.length) {
        showOverlay(); // ← 使用 Portal 方式显示并定位
        input.setAttribute('aria-expanded', 'true');
        setActiveIndex(-1);
      } else {
        hideSuggestions();
      }
    };

    const setActiveIndex = (index) => {
      logDebug('Setting active suggestion index', index);
      activeIndex = index;
      const buttons = suggestionsEl.querySelectorAll('.bo-cp-location__suggestion');
      let activeId = '';
      buttons.forEach((btn, idx) => {
        if (idx === index) {
          btn.classList.add('is-active');
          activeId = btn.id || '';
        } else {
          btn.classList.remove('is-active');
        }
      });
      if (activeId) {
        input.setAttribute('aria-activedescendant', activeId);
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    };

    const applySuggestion = async (item) => {
      if (!item) return;
      logDebug('Applying suggestion', item);
      hideSuggestions();
      input.value = item.description || '';
      if (clearBtn) {
        clearBtn.hidden = input.value.trim() === '';
      }
      placeField.value = item.place_id || '';
      let resolvedCity = '';
      let resolvedCountry = '';
      const sessionOptions = { getSessionToken: provideGoogleSessionToken };

      if (item.place_id) {
        logDebug('Fetching details for applied suggestion', item.place_id);
        const details = await fetchPlaceDetails(item.place_id, sessionOptions);
        if (details) {
          resolvedCity = (details.city || '').toString().trim();
          resolvedCountry = (details.country || '').toString().trim();
          if (details.place_id) {
            placeField.value = details.place_id;
          }
        }
      }

      if (item.place_id && (!resolvedCity || !resolvedCountry) && settings.placesKey) {
        logDebug('Attempting Google fallback for suggestion', item.place_id);
        const fallbackDetails = await fetchPlaceDetailsViaGoogle(item.place_id, sessionOptions);
        if (fallbackDetails) {
          if (!resolvedCity && fallbackDetails.city) {
            resolvedCity = fallbackDetails.city.toString().trim();
          }
          if (!resolvedCountry && fallbackDetails.country) {
            resolvedCountry = fallbackDetails.country.toString().trim();
          }
          if (!placeField.value && fallbackDetails.place_id) {
            placeField.value = fallbackDetails.place_id;
          }
        }
      }

      if ((!resolvedCity || !resolvedCountry) && Array.isArray(item.terms)) {
        logDebug('Falling back to terms parsing', item.terms);
        if (!resolvedCity && item.terms[0] && item.terms[0].value) {
          resolvedCity = item.terms[0].value;
        }
        const lastTerm = item.terms[item.terms.length - 1];
        if (!resolvedCountry && lastTerm && lastTerm.value) {
          resolvedCountry = lastTerm.value;
        }
      }

      if ((!resolvedCity || !resolvedCountry) && item.description) {
        logDebug('Falling back to description parsing', item.description);
        const parts = item.description.split(',').map((part) => part.trim()).filter(Boolean);
        if (!resolvedCountry && parts.length) {
          resolvedCountry = parts[parts.length - 1];
        }
        if (!resolvedCity && parts.length > 1) {
          resolvedCity = parts[0];
        }
      }

      cityField.value = resolvedCity || '';
      countryField.value = resolvedCountry || '';
      logDebug('Resolved suggestion details', { resolvedCity, resolvedCountry, placeId: placeField.value });

      if (!resolvedCity || !resolvedCountry) {
        setStatus(input.dataset.errorSelect || '', 'error');
      } else {
        setStatus('');
      }

      resetSessionContext();
    };

    const fetchSuggestions = async (query) => {
      const trimmed = (query || '').toString().trim();
      logDebug('fetchSuggestions called', trimmed);
      if (!trimmed || trimmed.length < 2) {
        hideSuggestions();
        setStatus('');
        lastQuery = '';
        resetSessionContext();
        return;
      }
      if (trimmed === lastQuery && currentSuggestions.length) {
        logDebug('Using cached suggestions for query', trimmed);
        renderSuggestions(currentSuggestions);
        updateOverlayPosition(); // 保守再对齐一次
        return;
      }

      lastQuery = trimmed;
      const currentToken = ++requestToken;
      logDebug('Fetching suggestions', { trimmed, token: currentToken });
      const activeLang = detectLang() || 'en';
      setStatus(input.dataset.loadingLabel || '', 'loading');
      hideSuggestions();

      let serverError = null;
      let serverResults = [];
      let googleTimeoutId = null;

      const finalizeEmptyState = () => {
        if (currentToken !== requestToken || currentSuggestions.length) {
          return;
        }
        hideSuggestions();
        if (serverError) {
          setStatus(input.dataset.fetchError || '', 'error');
        } else {
          setStatus(input.dataset.noResults || '', 'empty');
        }
        logDebug('finalizeEmptyState executed', { serverError: Boolean(serverError) });
      };

      if (settings.placesKey) {
        googleTimeoutId = setTimeout(() => {
          logDebug('Google suggestions timeout reached');
          finalizeEmptyState();
        }, 4000);
      }

      try {
        const params = new URLSearchParams({
          input: trimmed,
          lang: activeLang,
        });
        if (countryField.value) {
          params.set('country', countryField.value);
        }
        const sessionToken = ensureServerSessionToken();
        if (sessionToken) {
          params.set('session_token', sessionToken);
        }
        logDebug('Requesting server suggestions', params.toString());
        const res = await fetchWithTimeout(`${restUrl('places')}?${params.toString()}`, {
          credentials: 'same-origin'
        }, 5000);
        if (!res.ok) {
          logDebug('Server suggestions HTTP error', res.status);
          throw new Error(`HTTP ${res.status}`);
        }
        const payload = await res.json();
        logDebug('Server suggestions payload', payload);
        if (Array.isArray(payload.predictions)) {
          serverResults = payload.predictions;
        }
      } catch (err) {
        serverError = err;
        console.error('Failed to fetch suggestions', err);
        logDebug('Server suggestions error', err);
      }

      if (currentToken !== requestToken) {
        if (googleTimeoutId) {
          clearTimeout(googleTimeoutId);
        }
        logDebug('Suggestion request aborted due to stale token', { token: currentToken, latest: requestToken });
        return;
      }

      currentSuggestions = dedupeSuggestions(serverResults);
      if (currentSuggestions.length) {
        logDebug('Server suggestions ready', currentSuggestions);
        renderSuggestions(currentSuggestions);
        setStatus('');
        if (googleTimeoutId) {
          clearTimeout(googleTimeoutId);
          googleTimeoutId = null;
        }
        updateOverlayPosition(); // 再对齐一次
      } else if (!settings.placesKey) {
        finalizeEmptyState();
      }

      if (settings.placesKey) {
        fetchSuggestionsViaGoogle(trimmed, {
          lang: activeLang,
          getSessionToken: provideGoogleSessionToken,
        }).then((googleResults) => {
          if (googleTimeoutId) {
            clearTimeout(googleTimeoutId);
            googleTimeoutId = null;
          }
          if (currentToken !== requestToken) {
            logDebug('Google suggestions arrived too late', { token: currentToken, latest: requestToken });
            return;
          }
          if (!Array.isArray(googleResults) || !googleResults.length) {
            logDebug('Google suggestions empty', googleResults);
            finalizeEmptyState();
            return;
          }
          const combined = dedupeSuggestions(currentSuggestions.concat(googleResults));
          currentSuggestions = combined;
          if (combined.length) {
            logDebug('Combined suggestions ready', combined);
            renderSuggestions(combined);
            setStatus('');
            updateOverlayPosition();
          } else {
            finalizeEmptyState();
          }
        }).catch((err) => {
          if (googleTimeoutId) {
            clearTimeout(googleTimeoutId);
            googleTimeoutId = null;
          }
          console.error('Google suggestion fallback failed', err);
          logDebug('Google suggestion fallback error', err);
          finalizeEmptyState();
        });
      }

      if (!currentSuggestions.length && !settings.placesKey) {
        finalizeEmptyState();
      }
    };

    input.addEventListener('input', () => {
      logDebug('Location input change', input.value);
      resetHiddenFields();
      if (clearBtn) {
        clearBtn.hidden = input.value.trim() === '';
      }
      setStatus('');
      if (debounceTimer) {
        clearTimeout(debounceTimer);
      }
      const value = input.value;
      debounceTimer = setTimeout(() => {
        logDebug('Triggering debounced suggestion fetch', value);
        fetchSuggestions(value);
      }, 250);
    });

    input.addEventListener('focus', () => {
      logDebug('Location input focused');
      if (currentSuggestions.length) {
        renderSuggestions(currentSuggestions);
        updateOverlayPosition();
      }
    });

    input.addEventListener('keydown', (event) => {
      logDebug('Location input keydown', event.key);
      if (suggestionsEl.hidden) {
        if (event.key === 'Enter') {
          const message = input.dataset.errorSelect || '';
          if (message && (!cityField.value || !countryField.value)) {
            event.preventDefault();
            setStatus(message, 'error');
          }
        }
        return;
      }
      const maxIndex = currentSuggestions.length - 1;
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        const next = activeIndex >= maxIndex ? 0 : activeIndex + 1;
        setActiveIndex(next);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        const next = activeIndex <= 0 ? maxIndex : activeIndex - 1;
        setActiveIndex(next);
      } else if (event.key === 'Enter') {
        event.preventDefault();
        if (activeIndex >= 0 && currentSuggestions[activeIndex]) {
          applySuggestion(currentSuggestions[activeIndex]);
        }
      } else if (event.key === 'Escape') {
        event.preventDefault();
        hideSuggestions();
      }
    });

    suggestionsEl.addEventListener('mousedown', (event) => {
      const button = event.target.closest('.bo-cp-location__suggestion');
      if (button) {
        logDebug('Suggestion mousedown', button.dataset.index);
        event.preventDefault();
      }
    });

    suggestionsEl.addEventListener('click', (event) => {
      const button = event.target.closest('.bo-cp-location__suggestion');
      if (!button) return;
      event.preventDefault();
      logDebug('Suggestion clicked', button.dataset.index);
      const index = parseInt(button.dataset.index || '-1', 10);
      if (index >= 0 && currentSuggestions[index]) {
        applySuggestion(currentSuggestions[index]);
      }
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', (event) => {
        event.preventDefault();
        logDebug('Location clear clicked');
        input.value = '';
        resetHiddenFields();
        hideSuggestions();
        clearBtn.hidden = true;
        setStatus('');
        resetSessionContext();
        input.focus();
      });
    }

    document.addEventListener('click', (event) => {
      // 由于 suggestionsEl 已经被移动到 <body>，需要同时判断输入区域与浮层本身
      const target = event.target;
      const clickedInsideInput = wrapper.contains(target);
      const clickedInsideOverlay = suggestionsEl.contains(target);
      if (!clickedInsideInput && !clickedInsideOverlay) {
        logDebug('Outside click detected (including overlay), hiding suggestions');
        hideSuggestions();
      }
    });
  };

  initBirthDateToggle();
  initLocationPicker();

  document.addEventListener('submit', async (e)=>{
    const form = e.target;
    if (form.id !== 'bo-cp-form') return;
    e.preventDefault();
    logDebug('Form submission intercepted');

    const formData = new FormData(form);
    const lang = detectLang();
    logDebug('Detected submission language', lang);
    formData.set('lang', lang);
    const hidden = form.querySelector('input[name="lang"]');
    if (hidden) {
      hidden.value = lang;
    }
    let city = (formData.get('city') || '').toString().trim();
    let country = (formData.get('country') || '').toString().trim();
    const placeId = (formData.get('place_id') || '').toString().trim();
    logDebug('Initial submission data', { city, country, placeId });

    if ((!city || !country) && placeId) {
      try {
        const details = await fetchPlaceDetails(placeId);
        if (details) {
          if (!city && details.city) {
            city = details.city.toString().trim();
            formData.set('city', city);
            const cityInput = form.querySelector('input[name="city"]');
            if (cityInput) {
              cityInput.value = city;
            }
          }
          if (!country && details.country) {
            country = details.country.toString().trim();
            formData.set('country', country);
            const countryInput = form.querySelector('input[name="country"]');
            if (countryInput) {
              countryInput.value = country;
            }
          }
        }
        logDebug('Resolved submission data after REST details', { city, country });
      } catch (err) {
        console.error('Failed to resolve place details before submit', err);
        logDebug('Error resolving place details before submit', err);
      }
    }

    if (!city || !country) {
      logDebug('Submission blocked due to missing city/country', { city, country });
      const locationInput = form.querySelector('[data-location-input]');
      const message = locationInput ? (locationInput.dataset.errorSelect || 'Please select a city from the suggestions.') : 'Please select a city from the suggestions.';
      alert(message);
      if (locationInput) {
        locationInput.focus();
      }
      return;
    }
    const qs = new URLSearchParams(formData);
    const url = `${restUrl('geo')}?${qs.toString()}`;
    logDebug('Submitting geo request', url);

    const res = await fetch(url);
    const data = await res.json();
    logDebug('Geo response received', data);

    if (data.error) {
      alert(data.error);
      logDebug('Geo response error', data.error);
      return;
    }

    // show persona + overview
    $('#bo-cp-result').style.display = 'block';
    const displayTitle = data.display_title || data.displayTitle || data.name || data.persona_key || '';
    $('#bo-cp-persona-name').textContent = displayTitle;
    $('#bo-cp-overview').innerHTML = data.overview_html || '<p>(No overview)</p>';

    // cache for later section loads
    window.__boCP = {
      result_id: data.result_id,
      name: data.persona_key || data.name,
      persona_key: data.persona_key || data.name,
      display_title: displayTitle,
      token: data.token,
      lang: data.lang ? normalizeLang(data.lang) || lang : lang
    };
    logDebug('Cached persona result', window.__boCP);
    ensureState();
    updateDynamicSections(window.__boCP);
  });

  logDebug('bo-cp form script initialized');
  // Removed manual section loader; dynamic placeholders hydrate automatically.
})();