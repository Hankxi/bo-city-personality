(function(){
  const $ = (s, r=document) => r.querySelector(s);
  const config = window.boCpConfig || {};
  const strings = config.strings || {};
  const countries = Array.isArray(config.countries) ? config.countries : [];
  const endpoints = config.endpoints || {};

  const supportedLangs = (() => {
    if (Array.isArray(config.langs) && config.langs.length) {
      return config.langs.map((lang) => String(lang).toLowerCase());
    }
    return ['en'];
  })();

  function normalizeLang(value) {
    if (typeof value !== 'string') return '';
    const lowered = value.toLowerCase().trim();
    if (!lowered) return '';
    if (supportedLangs.includes(lowered)) return lowered;
    const hyphen = lowered.split('-')[0];
    if (supportedLangs.includes(hyphen)) return hyphen;
    const underscore = lowered.split('_')[0];
    if (supportedLangs.includes(underscore)) return underscore;
    if (lowered.length >= 2) {
      const short = lowered.slice(0, 2);
      if (supportedLangs.includes(short)) return short;
    }
    return '';
  }

  const fallbackLang = (() => {
    const direct = normalizeLang(config.lang || '');
    if (direct) return direct;
    return supportedLangs[0] || 'en';
  })();

  function detectLang() {
    const attempts = [];
    if (document.documentElement) {
      attempts.push(document.documentElement.getAttribute('lang'));
    }
    if (typeof config.lang === 'string') {
      attempts.push(config.lang);
    }
    if (window.boCpCurrentLang) {
      attempts.push(window.boCpCurrentLang);
    }
    if (window.__boCP && typeof window.__boCP.lang === 'string') {
      attempts.push(window.__boCP.lang);
    }
    if (navigator.languages && navigator.languages.length) {
      attempts.push.apply(attempts, navigator.languages);
    }
    if (navigator.language) {
      attempts.push(navigator.language);
    }

    for (const cand of attempts) {
      const normalized = normalizeLang(cand);
      if (normalized) {
        return normalized;
      }
    }

    return fallbackLang;
  }

  const countryInput = $('#bo-cp-country');
  const cityInput = $('#bo-cp-city');
  const placeInput = $('#bo-cp-place-id');
  const countryOptions = $('#bo-cp-country-options');
  const cityOptions = $('#bo-cp-city-options');
  const form = $('#bo-cp-form');
  const submitButton = $('#bo-cp-submit');
  const resultWrap = $('#bo-cp-result');
  const resultTitle = $('#bo-cp-result-title');
  const nameEl = $('#bo-cp-name');
  const overviewEl = $('#bo-cp-overview');
  const sectionInput = $('#bo-cp-section-key');
  const sectionButton = $('#bo-cp-load-section');
  const sectionHtml = $('#bo-cp-section-html');
  const birthInput = $('#bo-cp-birth-date');
  const birthToggle = $('#bo-cp-toggle-date');

  const countryIndex = (() => {
    const map = new Map();
    countries.forEach((country) => {
      const code = String(country.code || '').toLowerCase();
      const name = String(country.name || '').toLowerCase();
      const display = String(country.display || '').toLowerCase();
      const label = String(country.label || '').toLowerCase();
      if (code) map.set(code, country);
      if (name) map.set(name, country);
      if (display) map.set(display, country);
      if (label) map.set(label, country);
    });
    return map;
  })();

  function findCountry(value) {
    if (!value) return null;
    const lowered = value.toLowerCase();
    if (countryIndex.has(lowered)) {
      return countryIndex.get(lowered);
    }
    for (const country of countries) {
      const display = String(country.display || '').toLowerCase();
      const name = String(country.name || '').toLowerCase();
      if (display === lowered || name === lowered) {
        return country;
      }
    }
    return null;
  }

  function updateCountryDataset() {
    if (!countryInput) return;
    const value = countryInput.value.trim();
    const country = findCountry(value) || findCountry(countryInput.getAttribute('data-last-match') || '');
    if (country) {
      countryInput.dataset.code = country.code || '';
      countryInput.setAttribute('data-last-match', country.display || country.name || country.code || '');
    } else {
      delete countryInput.dataset.code;
      countryInput.removeAttribute('data-last-match');
    }
  }

  let cityPredictions = [];
  let cityTimer = null;
  let cityRequestId = 0;
  let cityAbort = null;

  function resetCityOptions() {
    if (cityOptions) {
      cityOptions.innerHTML = '';
    }
    cityPredictions = [];
    if (placeInput) {
      placeInput.value = '';
    }
  }

  function updatePlaceSelection() {
    if (!cityInput || !placeInput) return;
    const value = cityInput.value.trim();
    if (!value) {
      placeInput.value = '';
      return;
    }
    let match = cityPredictions.find((prediction) => prediction.description === value);
    if (!match && cityOptions) {
      const options = Array.from(cityOptions.options || []);
      for (const option of options) {
        if (option.value === value) {
          match = { place_id: option.getAttribute('data-place-id') || option.dataset.placeId || '', description: option.value };
          break;
        }
      }
    }
    if (match && match.place_id) {
      placeInput.value = match.place_id;
    } else {
      placeInput.value = '';
    }
  }

  function renderCityOptions(predictions) {
    cityPredictions = Array.isArray(predictions) ? predictions : [];
    if (!cityOptions) return;
    cityOptions.innerHTML = '';
    cityPredictions.forEach((prediction) => {
      if (!prediction || typeof prediction.description !== 'string') {
        return;
      }
      const option = document.createElement('option');
      option.value = prediction.description;
      if (prediction.place_id) {
        option.setAttribute('data-place-id', prediction.place_id);
      }
      option.textContent = prediction.description;
      cityOptions.appendChild(option);
    });
    updatePlaceSelection();
  }

  function buildUrl(base, params) {
    try {
      const url = new URL(base, window.location.origin);
      params.forEach((value, key) => {
        url.searchParams.set(key, value);
      });
      return url.toString();
    } catch (err) {
      const qs = params.toString();
      if (base.indexOf('?') === -1) {
        return base + '?' + qs;
      }
      return base + '&' + qs;
    }
  }

  function fetchCitySuggestions(query) {
    if (!cityInput) return;
    const endpoint = typeof endpoints.places === 'string' ? endpoints.places : '';
    if (!endpoint) {
      return;
    }
    if (cityAbort) {
      cityAbort.abort();
      cityAbort = null;
    }
    const requestId = ++cityRequestId;
    const params = new URLSearchParams();
    params.set('input', query);
    params.set('lang', detectLang());
    if (countryInput && countryInput.dataset.code) {
      params.set('country', countryInput.dataset.code);
    }
    const controller = new AbortController();
    cityAbort = controller;
    fetch(buildUrl(endpoint, params), { signal: controller.signal })
      .then((res) => {
        if (!res.ok) {
          throw new Error('HTTP ' + res.status);
        }
        return res.json();
      })
      .then((data) => {
        if (requestId !== cityRequestId) return;
        renderCityOptions(Array.isArray(data.predictions) ? data.predictions : []);
      })
      .catch((err) => {
        if (err && err.name === 'AbortError') {
          return;
        }
        console.warn('[bo-city-personality] city suggestions failed', err);
      });
  }

  function handleCityInput() {
    if (!cityInput) return;
    const value = cityInput.value.trim();
    if (!value) {
      resetCityOptions();
      return;
    }
    updatePlaceSelection();
    if (value.length < 2) {
      return;
    }
    if (cityTimer) {
      clearTimeout(cityTimer);
    }
    cityTimer = setTimeout(() => fetchCitySuggestions(value), 250);
  }

  if (countryInput) {
    updateCountryDataset();
    countryInput.addEventListener('input', () => {
      updateCountryDataset();
      handleCityInput();
    });
    countryInput.addEventListener('change', () => {
      updateCountryDataset();
      handleCityInput();
    });
  }

  if (cityInput) {
    cityInput.addEventListener('input', handleCityInput);
    cityInput.addEventListener('change', () => {
      updatePlaceSelection();
      handleCityInput();
    });
    cityInput.addEventListener('blur', updatePlaceSelection);
  }

  if (birthToggle && birthInput) {
    birthToggle.addEventListener('click', (event) => {
      event.preventDefault();
      if (birthInput.type === 'date') {
        birthInput.type = 'text';
        birthInput.placeholder = strings.birth_placeholder || 'YYYY-MM-DD';
        birthToggle.textContent = strings.birth_toggle_back || 'Switch to calendar';
      } else {
        birthInput.type = 'date';
        if (!birthInput.value) {
          birthInput.value = '2000-01-01';
        }
        birthToggle.textContent = strings.birth_toggle || 'Switch to manual entry';
      }
    });
  }

  function showError(message) {
    window.alert(message || strings.generic_error || 'Something went wrong, please try again.');
  }

  function setButtonLoading(button, loading) {
    if (!button) return;
    if (loading) {
      button.disabled = true;
      button.setAttribute('data-loading', '1');
    } else {
      button.disabled = false;
      button.removeAttribute('data-loading');
    }
  }

  async function handleFormSubmit(event) {
    if (!form) return;
    event.preventDefault();
    const langInput = form.querySelector('input[name="lang"]');
    const detectedLang = detectLang();
    if (langInput) {
      langInput.value = detectedLang;
    }

    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    const endpoint = buildUrl('/wp-json/bo/v1/geo', params);

    setButtonLoading(submitButton, true);
    try {
      const res = await fetch(endpoint, { method: 'GET' });
      let data;
      try {
        data = await res.json();
      } catch (err) {
        throw new Error('invalid_json');
      }
      if (!res.ok || (data && data.code && !data.result_id)) {
        const message = (data && data.message) ? data.message : strings.generic_error;
        showError(message);
        return;
      }

      const displayName = data.display_title || data.name || '';
      const personaKey = data.persona_key || data.name || '';
      if (resultWrap) {
        resultWrap.style.display = 'block';
      }
      if (resultTitle && strings.result_title) {
        resultTitle.textContent = strings.result_title;
      }
      if (nameEl) {
        nameEl.textContent = displayName;
      }
      if (overviewEl) {
        overviewEl.innerHTML = data.overview_html || '<p>' + (strings.overview_empty || '(No overview)') + '</p>';
      }
      if (sectionHtml) {
        sectionHtml.innerHTML = '';
      }

      const responseLang = normalizeLang(data.lang) || detectedLang;
      window.__boCP = {
        result_id: data.result_id,
        name: personaKey,
        token: data.token,
        display_title: displayName,
        lang: responseLang
      };
    } catch (err) {
      if (err && err.message === 'invalid_json') {
        showError(strings.generic_error);
      } else {
        console.error('[bo-city-personality] geo lookup failed', err);
        showError(strings.generic_error);
      }
    } finally {
      setButtonLoading(submitButton, false);
    }
  }

  if (form) {
    form.addEventListener('submit', handleFormSubmit);
  }

  if (sectionButton) {
    sectionButton.addEventListener('click', async (event) => {
      event.preventDefault();
      const state = window.__boCP;
      if (!state) {
        showError(strings.compute_first || 'Compute first.');
        return;
      }
      const key = sectionInput ? (sectionInput.value.trim() || 'overview') : 'overview';
      const detectedLang = detectLang();
      state.lang = detectedLang;
      const params = new URLSearchParams({
        result_id: state.result_id,
        name: state.name,
        token: state.token,
        section: key,
        lang: detectedLang
      });
      try {
        const res = await fetch(buildUrl('/wp-json/bo/v1/result', params));
        const data = await res.json();
        if (!res.ok || (data && data.code && !data.html)) {
          showError((data && data.message) ? data.message : strings.generic_error);
          return;
        }
        if (sectionHtml) {
          sectionHtml.innerHTML = data.html || '<p>' + (strings.section_empty || '(empty)') + '</p>';
        }
      } catch (err) {
        console.error('[bo-city-personality] section fetch failed', err);
        showError(strings.generic_error);
      }
    });
  }
})();