(function(){
  const $ = (s, r=document) => r.querySelector(s);

  const supportedLangs = (() => {
    if (window.boCpConfig && Array.isArray(window.boCpConfig.langs) && window.boCpConfig.langs.length) {
      return window.boCpConfig.langs.map((lang) => String(lang).toLowerCase());
    }
    return ['en'];
  })();

  const fallbackLang = (() => {
    if (window.boCpConfig && typeof window.boCpConfig.lang === 'string' && window.boCpConfig.lang) {
      return normalizeLang(window.boCpConfig.lang);
    }
    return supportedLangs[0] || 'en';
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

  function detectLang() {
    const attempts = [];
    if (document.documentElement) {
      attempts.push(document.documentElement.getAttribute('lang'));
    }
    if (window.boCpConfig && typeof window.boCpConfig.lang === 'string') {
      attempts.push(window.boCpConfig.lang);
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

  document.addEventListener('submit', async (e)=>{
    const form = e.target;
    if (form.id !== 'bo-cp-form') return;
    e.preventDefault();

    const langInput = form.querySelector('input[name="lang"]');
    const detectedLang = detectLang();
    if (langInput) {
      langInput.value = detectedLang;
    }

    const qs = new URLSearchParams(new FormData(form));
    const url = `/wp-json/bo/v1/geo?` + qs.toString();

    const res = await fetch(url);
    const data = await res.json();

    if (data.error) {
      alert(data.error);
      return;
    }

    // show persona + overview
    const displayName = data.display_title || data.name || '';
    const personaKey = data.persona_key || data.name || '';
    $('#bo-cp-result').style.display = 'block';
    $('#bo-cp-name').textContent = displayName;
    $('#bo-cp-overview').innerHTML = data.overview_html || '<p>(No overview)</p>';

    // cache for later section loads
    const responseLang = normalizeLang(data.lang) || detectedLang;
    window.__boCP = {
      result_id: data.result_id,
      name: personaKey,
      token: data.token,
      display_title: displayName,
      lang: responseLang
    };
  });

  document.addEventListener('click', async (e)=>{
    const btn = e.target;
    if (btn.id !== 'bo-cp-load-section') return;
    e.preventDefault();

    const st = window.__boCP;
    if (!st) return alert('Compute first.');

    const key = $('#bo-cp-section-key').value.trim() || 'overview';
    const detectedLang = detectLang();
    st.lang = detectedLang;
    const qs = new URLSearchParams({
      result_id: st.result_id,
      name: st.name,
      token: st.token,
      section: key,
      lang: detectedLang
    });

    const res = await fetch(`/wp-json/bo/v1/result?` + qs.toString());
    const data = await res.json();
    if (data.error) {
      alert(data.error);
      return;
    }
    $('#bo-cp-section-html').innerHTML = data.html || '<p>(empty)</p>';
  });
})();