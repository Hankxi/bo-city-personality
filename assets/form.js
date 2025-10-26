(function(){
  const settings = window.boCPData || {};
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
    return window.__boCP;
  };

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
      if (normalized) return normalized;
    }
    const configured = normalizeLang(settings.lang || '');
    if (configured) {
      return configured;
    }
    const html = normalizeLang(document.documentElement && document.documentElement.lang);
    if (html) return html;
    const body = normalizeLang(document.body && document.body.getAttribute('lang'));
    if (body) return body;
    const hidden = document.querySelector('#bo-cp-form input[name="lang"]');
    if (hidden && hidden.value) {
      const normalized = normalizeLang(hidden.value);
      if (normalized) return normalized;
    }
    return 'en';
  };

  let googlePlacesLoader = null;
  const ensureGooglePlaces = () => {
    if (!settings.placesKey) {
      return Promise.reject(new Error('Missing Google Places key'));
    }
    if (window.google && window.google.maps && window.google.maps.places) {
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
          resolve(window.google.maps);
        } else {
          googlePlacesLoader = null;
          reject(new Error('Google Places library unavailable'));
        }
      };
      head.appendChild(script);
    });
    return googlePlacesLoader;
  };

  const googleAutocompleteCache = {};

  const fetchSuggestionsViaGoogle = async (query) => {
    const trimmed = (query || '').trim();
    if (trimmed.length < 2) {
      return [];
    }
    const cacheKey = `${trimmed}|${detectLang()}`;
    if (googleAutocompleteCache[cacheKey]) {
      return googleAutocompleteCache[cacheKey];
    }
    try {
      const maps = await ensureGooglePlaces();
      if (!maps || !maps.places) {
        return [];
      }
      const service = fetchSuggestionsViaGoogle._service || new maps.places.AutocompleteService();
      fetchSuggestionsViaGoogle._service = service;
      const request = {
        input: trimmed,
        types: ['(cities)'],
        language: detectLang() || 'en',
      };
      const predictions = await new Promise((resolve, reject) => {
        service.getPlacePredictions(request, (items, status) => {
          if (status === maps.places.PlacesServiceStatus.OK && Array.isArray(items)) {
            resolve(items);
            return;
          }
          if (status === maps.places.PlacesServiceStatus.ZERO_RESULTS) {
            resolve([]);
            return;
          }
          reject(new Error(`Google Places status ${status}`));
        });
      });
      const normalized = predictions.map((prediction) => ({
        place_id: prediction.place_id || '',
        description: prediction.description || '',
        matched_substrings: prediction.matched_substrings || [],
        terms: prediction.terms || [],
      })).filter((item) => item.description);
      googleAutocompleteCache[cacheKey] = normalized;
      return normalized;
    } catch (err) {
      console.error('Google Places fallback failed', err);
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

  const fetchPlaceDetailsViaGoogle = async (placeId) => {
    if (!placeId || !settings.placesKey) {
      return null;
    }
    try {
      const maps = await ensureGooglePlaces();
      if (!maps || !maps.places) {
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
      const result = await new Promise((resolve, reject) => {
        service.getDetails(request, (place, status) => {
          if (status === maps.places.PlacesServiceStatus.OK && place) {
            resolve(place);
            return;
          }
          if (status === maps.places.PlacesServiceStatus.ZERO_RESULTS) {
            resolve(null);
            return;
          }
          reject(new Error(`Google Place details status ${status}`));
        });
      });
      if (!result) {
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
      return null;
    }
  };

  const fetchSections = async (persona, lang, keys) => {
    const state = ensureState();
    if (!Array.isArray(keys) || !keys.length) {
      return {};
    }

    const canonPersona = canonKey(persona);
    const normalizedLang = (lang || 'en').toLowerCase();

    if (!state.sectionsCache[canonPersona]) {
      state.sectionsCache[canonPersona] = {};
    }
    if (!state.sectionsCache[canonPersona][normalizedLang]) {
      state.sectionsCache[canonPersona][normalizedLang] = {};
    }

    const langCache = state.sectionsCache[canonPersona][normalizedLang];

    const missing = keys.filter((k) => !(k in langCache));
    if (missing.length) {
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
          }
          fetched = true;
        } else {
          console.error('Failed to fetch sections', res.status, res.statusText);
        }
      } catch (err) {
        console.error('Failed to fetch sections', err);
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
    return out;
  };

  const renderSection = (el, row, lang, persona, showTitle) => {
    if (!el) return;
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
      return;
    }

    const persona = options.persona_key || options.name || '';
    if (!persona) {
      return;
    }

    const lang = (options.lang || 'en').toLowerCase();
    const keys = Array.from(new Set(placeholders.map((el) => el.dataset.section).filter(Boolean)));
    if (!keys.length) {
      return;
    }

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
      renderSection(el, row, usedLang, canonKey(persona), showTitle);
    });
  };

  const placeDetailsCache = new Map();

  const initBirthDateToggle = () => {
    const form = document.querySelector('#bo-cp-form');
    if (!form) return;
    const input = form.querySelector('[data-date-input]');
    const toggle = form.querySelector('[data-date-toggle]');
    if (!input || !toggle) return;

    const manualLabel = toggle.dataset.manualLabel || 'Manual Entry';
    const pickerLabel = toggle.dataset.pickerLabel || 'Use Date Picker';
    let manualMode = false;

    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      manualMode = !manualMode;
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

  const fetchPlaceDetails = async (placeId) => {
    if (!placeId) return null;
    if (placeDetailsCache.has(placeId)) {
      return placeDetailsCache.get(placeId);
    }
    const params = new URLSearchParams({ place_id: placeId, lang: 'en' });
    try {
      const res = await fetch(`${restUrl('place-details')}?${params.toString()}`);
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      const payload = await res.json();
      if (payload && (!payload.city || !payload.country) && settings.placesKey) {
        const supplement = await fetchPlaceDetailsViaGoogle(placeId);
        if (supplement) {
          const merged = Object.assign({}, payload, {
            city: payload.city || supplement.city || '',
            country: payload.country || supplement.country || '',
            place_id: payload.place_id || supplement.place_id || placeId,
          });
          placeDetailsCache.set(placeId, merged);
          return merged;
        }
      }
      placeDetailsCache.set(placeId, payload);
      return payload;
    } catch (err) {
      console.error('Failed to fetch place details', err);
      if (settings.placesKey) {
        const fallback = await fetchPlaceDetailsViaGoogle(placeId);
        if (fallback) {
          placeDetailsCache.set(placeId, fallback);
          return fallback;
        }
      }
      return null;
    }
  };

  const initLocationPicker = () => {
    const form = document.querySelector('#bo-cp-form');
    if (!form) return;
    const wrapper = form.querySelector('[data-location-wrapper]');
    if (!wrapper) return;
    const input = wrapper.querySelector('[data-location-input]');
    const suggestionsEl = wrapper.querySelector('[data-location-suggestions]');
    const statusEl = wrapper.querySelector('[data-location-status]');
    const clearBtn = wrapper.querySelector('[data-location-clear]');
    const cityField = form.querySelector('input[name="city"]');
    const countryField = form.querySelector('input[name="country"]');
    const placeField = form.querySelector('input[name="place_id"]');
    if (!input || !suggestionsEl || !statusEl || !cityField || !countryField || !placeField) {
      return;
    }

    let debounceTimer = null;
    let requestToken = 0;
    let currentSuggestions = [];
    let activeIndex = -1;
    let lastQuery = '';

    const setStatus = (message = '', state = '') => {
      if (!statusEl) return;
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
      suggestionsEl.innerHTML = '';
      suggestionsEl.hidden = true;
      activeIndex = -1;
      input.setAttribute('aria-expanded', 'false');
    };

    const resetHiddenFields = () => {
      cityField.value = '';
      countryField.value = '';
      placeField.value = '';
    };

    const renderSuggestions = (items) => {
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
        suggestionsEl.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        setActiveIndex(-1);
      } else {
        hideSuggestions();
      }
    };

    const setActiveIndex = (index) => {
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
      hideSuggestions();
      input.value = item.description || '';
      if (clearBtn) {
        clearBtn.hidden = input.value.trim() === '';
      }
      placeField.value = item.place_id || '';
      let resolvedCity = '';
      let resolvedCountry = '';

      if (item.place_id) {
        const details = await fetchPlaceDetails(item.place_id);
        if (details) {
          resolvedCity = (details.city || '').toString().trim();
          resolvedCountry = (details.country || '').toString().trim();
          if (details.place_id) {
            placeField.value = details.place_id;
          }
        }
      }

      if (item.place_id && (!resolvedCity || !resolvedCountry) && settings.placesKey) {
        const fallbackDetails = await fetchPlaceDetailsViaGoogle(item.place_id);
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
        if (!resolvedCity && item.terms[0] && item.terms[0].value) {
          resolvedCity = item.terms[0].value;
        }
        const lastTerm = item.terms[item.terms.length - 1];
        if (!resolvedCountry && lastTerm && lastTerm.value) {
          resolvedCountry = lastTerm.value;
        }
      }

      if ((!resolvedCity || !resolvedCountry) && item.description) {
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

      if (!resolvedCity || !resolvedCountry) {
        setStatus(input.dataset.errorSelect || '', 'error');
      } else {
        setStatus('');
      }
    };

    const fetchSuggestions = async (query) => {
      const trimmed = query.trim();
      if (!trimmed || trimmed.length < 2) {
        hideSuggestions();
        setStatus('');
        return;
      }
      if (trimmed === lastQuery && currentSuggestions.length) {
        renderSuggestions(currentSuggestions);
        return;
      }

      lastQuery = trimmed;
      const currentToken = ++requestToken;
      setStatus(input.dataset.loadingLabel || '', 'loading');
      hideSuggestions();

      try {
        const params = new URLSearchParams({
          input: trimmed,
          lang: detectLang(),
        });
        const res = await fetch(`${restUrl('places')}?${params.toString()}`);
        if (!res.ok) {
          throw new Error(`HTTP ${res.status}`);
        }
        const payload = await res.json();
        if (currentToken !== requestToken) {
          return;
        }
        const combined = Array.isArray(payload.predictions) ? payload.predictions.slice() : [];
        if (settings.placesKey) {
          const googleResults = await fetchSuggestionsViaGoogle(trimmed);
          if (googleResults && googleResults.length) {
            const seen = new Set();
            combined.forEach((item) => {
              const key = (item && (item.place_id || item.description)) ? `${item.place_id || ''}|${item.description || ''}` : '';
              if (key) {
                seen.add(key);
              }
            });
            googleResults.forEach((item) => {
              const key = (item && (item.place_id || item.description)) ? `${item.place_id || ''}|${item.description || ''}` : '';
              if (!key || seen.has(key)) {
                return;
              }
              combined.push(item);
              seen.add(key);
            });
          }
        }
        currentSuggestions = combined;
        if (!currentSuggestions.length) {
          hideSuggestions();
          setStatus(input.dataset.noResults || '', 'empty');
          return;
        }
        renderSuggestions(currentSuggestions);
        setStatus('');
      } catch (err) {
        console.error('Failed to fetch suggestions', err);
        if (currentToken === requestToken) {
          let resolved = [];
          if (settings.placesKey) {
            resolved = await fetchSuggestionsViaGoogle(trimmed);
          }
          if (resolved.length) {
            currentSuggestions = resolved;
            renderSuggestions(currentSuggestions);
            setStatus('');
          } else {
            hideSuggestions();
            setStatus(input.dataset.fetchError || '', 'error');
          }
        }
      }
    };

    input.addEventListener('input', () => {
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
        fetchSuggestions(value);
      }, 250);
    });

    input.addEventListener('focus', () => {
      if (currentSuggestions.length) {
        renderSuggestions(currentSuggestions);
      }
    });

    input.addEventListener('keydown', (event) => {
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
        event.preventDefault();
      }
    });

    suggestionsEl.addEventListener('click', (event) => {
      const button = event.target.closest('.bo-cp-location__suggestion');
      if (!button) return;
      event.preventDefault();
      const index = parseInt(button.dataset.index || '-1', 10);
      if (index >= 0 && currentSuggestions[index]) {
        applySuggestion(currentSuggestions[index]);
      }
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', (event) => {
        event.preventDefault();
        input.value = '';
        resetHiddenFields();
        hideSuggestions();
        clearBtn.hidden = true;
        setStatus('');
        input.focus();
      });
    }

    document.addEventListener('click', (event) => {
      if (!wrapper.contains(event.target)) {
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

    const formData = new FormData(form);
    const lang = detectLang();
    formData.set('lang', lang);
    const hidden = form.querySelector('input[name="lang"]');
    if (hidden) {
      hidden.value = lang;
    }
    const city = (formData.get('city') || '').toString().trim();
    const country = (formData.get('country') || '').toString().trim();
    if (!city || !country) {
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

    const res = await fetch(url);
    const data = await res.json();

    if (data.error) {
      alert(data.error);
      return;
    }

    // show persona + overview
    $('#bo-cp-result').style.display = 'block';
    const displayTitle = data.display_title || data.name || data.persona_key || '';
    $('#bo-cp-name').textContent = displayTitle;
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
    ensureState();
    updateDynamicSections(window.__boCP);
  });

  // Removed manual section loader; dynamic placeholders hydrate automatically.
})();
