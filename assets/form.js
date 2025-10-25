(function(){
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
        const res = await fetch(`/wp-json/bo/v1/sections?${params.toString()}`);
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

  document.addEventListener('submit', async (e)=>{
    const form = e.target;
    if (form.id !== 'bo-cp-form') return;
    e.preventDefault();

    const qs = new URLSearchParams(new FormData(form));
    const url = `/wp-json/bo/v1/geo?` + qs.toString();

    const res = await fetch(url);
    const data = await res.json();

    if (data.error) {
      alert(data.error);
      return;
    }

    // show persona + overview
    $('#bo-cp-result').style.display = 'block';
    $('#bo-cp-name').textContent = data.name || '';
    $('#bo-cp-overview').innerHTML = data.overview_html || '<p>(No overview)</p>';

    // cache for later section loads
    window.__boCP = {
      result_id: data.result_id,
      name: data.name,
      persona_key: data.persona_key || data.name,
      token: data.token,
      lang: data.lang || 'en'
    };
    ensureState();
    updateDynamicSections(window.__boCP);
  });

  document.addEventListener('click', async (e)=>{
    const btn = e.target;
    if (btn.id !== 'bo-cp-load-section') return;
    e.preventDefault();

    const st = window.__boCP;
    if (!st) return alert('Compute first.');

    const key = $('#bo-cp-section-key').value.trim() || 'overview';
    const qs = new URLSearchParams({
      result_id: st.result_id,
      name: st.name,
      token: st.token,
      section: key
    });

    const res = await fetch(`/wp-json/bo/v1/result?` + qs.toString());
    const data = await res.json();
    if (data.error) {
      alert(data.error);
      return;
    }
    $('#bo-cp-section-html').innerHTML = data.html || '<p>(empty)</p>';
    if (window.__boCP) {
      window.__boCP.lang = data.lang || window.__boCP.lang;
      updateDynamicSections(window.__boCP);
    }
  });
})();
