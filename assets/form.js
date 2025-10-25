(function(){
  const $ = (s, r=document) => r.querySelector(s);

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
      token: data.token
    };
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
  });
})();
