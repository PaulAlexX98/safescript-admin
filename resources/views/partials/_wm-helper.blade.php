<style>
  .cf-helper-bar{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0 8px}
  .cf-helper-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.04);cursor:pointer;transition:background .15s ease,border-color .15s ease}
  .cf-helper-btn:hover{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.26)}
  .cf-helper-host{display:none;margin:8px 0 16px}
  .cf-helper-host.is-open{display:block}
  .cf-enh-card{border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:14px 16px;background:rgba(255,255,255,.03);margin-top:12px}
  .cf-enh-title{font-size:13px;font-weight:600;margin:0 0 10px 0}
  .cf-enh-sub{font-size:12px;opacity:.8;margin:6px 0 0 0}
  .cf-enh-grid{display:grid;grid-template-columns:1fr;gap:10px}
  @media(min-width:768px){.cf-enh-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
  .cf-enh-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .cf-enh-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.05);cursor:pointer;transition:background .15s ease,border-color .15s ease}
  .cf-enh-btn:hover{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.24)}
  .cf-enh-btn[disabled]{opacity:.55;cursor:not-allowed}
  .cf-enh-result{font-size:14px;font-weight:600}
  .cf-enh-muted{font-size:12px;opacity:.72}
  .cf-gp-results{display:flex;flex-direction:column;gap:8px;margin-top:10px}
  .cf-gp-result{width:100%;text-align:left;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);border-radius:10px;padding:10px 12px;cursor:pointer}
  .cf-gp-result:hover{background:rgba(255,255,255,.07);border-color:rgba(255,255,255,.2)}
  .cf-gp-result strong{display:block;font-size:13px}
  .cf-gp-result span{display:block;font-size:12px;opacity:.8;margin-top:2px}
</style>

<div class="cf-helper-bar">
    <button type="button" class="cf-helper-btn" id="wm-open-bmi">Open BMI calculator</button>
    <button type="button" class="cf-helper-btn" id="wm-open-gp">Open GP search</button>
</div>
<div id="wm-helper-host" class="cf-helper-host"></div>

<script>
(function(){
  var form = document.getElementById('cf_risk-assessment');
  if (!form) return;

  var aliases = window.__cfAliases || {};

  function slugValue(x){
    if (x === true) return 'true';
    if (x === false) return 'false';
    x = (x == null ? '' : String(x)).toLowerCase().trim();
    return x.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  function canonicalName(s){
    if (s == null) return s;
    var k = slugValue(s);
    return aliases[k] || aliases[s] || s;
  }

  function getFirstExisting(names){
    for (var i = 0; i < names.length; i++) {
      var n = canonicalName(names[i]);
      var el = form.querySelector('[name="' + CSS.escape(n) + '"]') || form.querySelector('#' + CSS.escape(n));
      if (el) return el;
    }
    return null;
  }

  function parseNum(v){
    if (v == null) return null;
    var s = String(v).trim().replace(/,/g, '.');
    if (!s) return null;
    var n = parseFloat(s);
    return Number.isFinite(n) ? n : null;
  }

  function calcBmiMetric(heightCm, weightKg){
    var h = parseNum(heightCm);
    var w = parseNum(weightKg);
    if (!h || !w || h <= 0 || w <= 0) return null;
    var hm = h / 100;
    if (!hm) return null;
    return w / (hm * hm);
  }

  function calcBmiImperial(feet, inches, stone, pounds){
    var ft = parseNum(feet) || 0;
    var inch = parseNum(inches) || 0;
    var st = parseNum(stone) || 0;
    var lb = parseNum(pounds) || 0;
    var totalInches = (ft * 12) + inch;
    var totalPounds = (st * 14) + lb;
    if (!totalInches || !totalPounds || totalInches <= 0 || totalPounds <= 0) return null;
    return (totalPounds / (totalInches * totalInches)) * 703;
  }

  function bmiBand(bmi){
    if (!Number.isFinite(bmi)) return '';
    if (bmi < 18.5) return 'Underweight';
    if (bmi < 25) return 'Healthy weight';
    if (bmi < 30) return 'Overweight';
    return 'Obesity';
  }

  function parseCsvLine(line){
    var out = [];
    var cur = '';
    var inQuotes = false;
    for (var i = 0; i < line.length; i++) {
      var ch = line[i];
      if (ch === '"') {
        if (inQuotes && line[i + 1] === '"') { cur += '"'; i++; }
        else { inQuotes = !inQuotes; }
      } else if (ch === ',' && !inQuotes) {
        out.push(cur);
        cur = '';
      } else {
        cur += ch;
      }
    }
    out.push(cur);
    return out;
  }

  function parseCsv(text){
    if (!text) return [];
    var lines = String(text).split(/\r?\n/).filter(Boolean);
    if (!lines.length) return [];
    var headers = parseCsvLine(lines.shift()).map(function(h){ return String(h || '').trim(); });
    return lines.map(function(line){
      var cols = parseCsvLine(line);
      var row = {};
      headers.forEach(function(h, idx){ row[h] = cols[idx] != null ? String(cols[idx]).trim() : ''; });
      return row;
    });
  }

  function formatPractice(item){
    if (!item) return { title: '', subtitle: '' };
    var name = item.name || item.practice || item.organisation || item.practice_name || item['Practice Name'] || item['Organisation Name'] || item['Name'] || '';
    var code = item.code || item.practice_code || item['Organisation Code'] || item['Code'] || '';
    var email = item.email || item.practice_email || item['Email Address'] || item['Email'] || '';
    var address = [
      item.address,
      item.address1 || item['Address Line 1'],
      item.address2 || item['Address Line 2'],
      item.city || item.town || item['Post Town'],
      item.postcode || item['Postcode']
    ].filter(Boolean).join(', ');
    var title = [name, code ? '(' + code + ')' : ''].filter(Boolean).join(' ');
    var subtitle = [address, email].filter(Boolean).join(' • ');
    return { title: title || name || code || 'Practice', subtitle: subtitle };
  }

  async function searchEpracurLocal(q){
    var query = String(q || '').trim().toLowerCase();
    if (!query) return [];
    try {
      var res = await fetch('/data/epraccur.csv', { credentials: 'same-origin' });
      if (!res.ok) return [];
      var text = await res.text();
      var rows = parseCsv(text);
      return rows.filter(function(row){
        var blob = Object.values(row || {}).join(' ').toLowerCase();
        return blob.indexOf(query) !== -1;
      }).slice(0, 8).map(function(row){
        return {
          name: row['Practice Name'] || row['Organisation Name'] || row['Name'] || '',
          code: row['Organisation Code'] || row['Code'] || '',
          email: row['Email Address'] || row['Email'] || '',
          address1: row['Address Line 1'] || '',
          address2: row['Address Line 2'] || '',
          city: row['Post Town'] || '',
          postcode: row['Postcode'] || ''
        };
      });
    } catch (e) {
      return [];
    }
  }

  function ensureHelperHost(){
    return document.getElementById('wm-helper-host');
  }

  function openBmiHelper(){
    var host = ensureHelperHost();
    if (!host) return;
    host.classList.add('is-open');
    if (host.querySelector('.js-bmi-enh')) return;

    var bmiInput = getFirstExisting(['bmi']);
    if (!bmiInput) {
      host.innerHTML = '<div class="cf-enh-card js-bmi-enh"><div class="cf-enh-title">BMI calculator</div><div class="cf-enh-sub">BMI field not found on this form.</div></div>';
      return;
    }

    var heightCm = getFirstExisting(['height_cm','heightcm','height']);
    var weightKg = getFirstExisting(['weight_kg','weightkg','weight']);
    var heightFt = getFirstExisting(['height_ft','heightft','height_feet','feet','ft']);
    var heightIn = getFirstExisting(['height_in','heightin','height_inches','inches','inch']);
    var weightSt = getFirstExisting(['weight_st','weightst','weight_stone','stone','st']);
    var weightLb = getFirstExisting(['weight_lb','weightlb','weight_lbs','pounds','lbs','lb']);

    var card = document.createElement('div');
    card.className = 'cf-enh-card js-bmi-enh';
    card.innerHTML = ''+
      '<div class="cf-enh-title">BMI calculator</div>'+
      '<div class="cf-enh-grid">'+
        '<div class="cf-enh-muted">Reads the height and weight fields already on the form and fills BMI.</div>'+
        '<div class="cf-enh-row">'+
          '<button type="button" class="cf-enh-btn js-bmi-calc">Calculate BMI</button>'+
          '<div class="cf-enh-result js-bmi-result">—</div>'+
        '</div>'+
      '</div>'+
      '<p class="cf-enh-sub js-bmi-sub">Supports metric and imperial keys.</p>';
    host.innerHTML = '';
    host.appendChild(card);

    var btn = card.querySelector('.js-bmi-calc');
    var result = card.querySelector('.js-bmi-result');
    var sub = card.querySelector('.js-bmi-sub');

    function updateBmi(){
      var bmi = null;
      if (heightCm && weightKg) bmi = calcBmiMetric(heightCm.value, weightKg.value);
      if (!Number.isFinite(bmi) && (heightFt || heightIn || weightSt || weightLb)) {
        bmi = calcBmiImperial(heightFt ? heightFt.value : null, heightIn ? heightIn.value : null, weightSt ? weightSt.value : null, weightLb ? weightLb.value : null);
      }
      if (!Number.isFinite(bmi)) {
        result.textContent = '—';
        sub.textContent = 'Enter valid height and weight values to calculate BMI.';
        return;
      }
      var rounded = (Math.round(bmi * 10) / 10).toFixed(1);
      bmiInput.value = rounded;
      bmiInput.dispatchEvent(new Event('input', { bubbles: true }));
      bmiInput.dispatchEvent(new Event('change', { bubbles: true }));
      try { localStorage.setItem('raf.bmi', rounded); } catch (e) {}
      result.textContent = rounded;
      sub.textContent = bmiBand(bmi);
    }

    btn.addEventListener('click', updateBmi);
    [heightCm, weightKg, heightFt, heightIn, weightSt, weightLb].filter(Boolean).forEach(function(el){
      el.addEventListener('input', updateBmi);
      el.addEventListener('change', updateBmi);
    });
  }

  function openGpHelper(){
    var host = ensureHelperHost();
    if (!host) return;
    host.classList.add('is-open');
    if (host.querySelector('.js-gp-enh')) return;

    var gpInput = getFirstExisting(['gp']);
    if (!gpInput) {
      host.innerHTML = '<div class="cf-enh-card js-gp-enh"><div class="cf-enh-title">Find GP practice</div><div class="cf-enh-sub">GP field not found on this form.</div></div>';
      return;
    }

    var gpEmailInput = getFirstExisting(['gp_email','gp-email']);
    var card = document.createElement('div');
    card.className = 'cf-enh-card js-gp-enh';
    card.innerHTML = ''+
      '<div class="cf-enh-title">Find GP practice</div>'+
      '<div class="cf-enh-grid">'+
        '<input type="text" class="cf-input js-gp-query" placeholder="Search by GP practice name, postcode or code">'+
        '<div class="cf-enh-row">'+
          '<button type="button" class="cf-enh-btn js-gp-search">Search</button>'+
          '<div class="cf-enh-muted js-gp-status">Type a search and choose a result.</div>'+
        '</div>'+
      '</div>'+
      '<div class="cf-gp-results js-gp-results"></div>';
    host.innerHTML = '';
    host.appendChild(card);

    var queryEl = card.querySelector('.js-gp-query');
    var searchBtn = card.querySelector('.js-gp-search');
    var statusEl = card.querySelector('.js-gp-status');
    var resultsEl = card.querySelector('.js-gp-results');

    function applyPractice(item){
      var fp = formatPractice(item);
      gpInput.value = fp.title || item.name || '';
      gpInput.dispatchEvent(new Event('input', { bubbles: true }));
      gpInput.dispatchEvent(new Event('change', { bubbles: true }));
      if (gpEmailInput) {
        gpEmailInput.value = item.email || '';
        gpEmailInput.dispatchEvent(new Event('input', { bubbles: true }));
        gpEmailInput.dispatchEvent(new Event('change', { bubbles: true }));
      }
      statusEl.textContent = 'Selected ' + (fp.title || 'practice');
      resultsEl.innerHTML = '';
    }

    function renderResults(items){
      resultsEl.innerHTML = '';
      if (!items || !items.length) {
        statusEl.textContent = 'No GP practices found.';
        return;
      }
      statusEl.textContent = items.length + ' result' + (items.length === 1 ? '' : 's') + ' found';
      items.forEach(function(item){
        var fp = formatPractice(item);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cf-gp-result';
        btn.innerHTML = '<strong>' + (fp.title || 'Practice') + '</strong><span>' + (fp.subtitle || 'Select this practice') + '</span>';
        btn.addEventListener('click', function(){ applyPractice(item); });
        resultsEl.appendChild(btn);
      });
    }

    async function runGpSearch(qOverride){
      var q = String(qOverride != null ? qOverride : queryEl.value || '').trim();
      if (!q) {
        statusEl.textContent = 'Enter a search term first.';
        resultsEl.innerHTML = '';
        return;
      }
      statusEl.textContent = 'Searching…';
      resultsEl.innerHTML = '';
      searchBtn.disabled = true;
      try {
        var apiResults = [];
        try {
          var res = await fetch('/api/gp-search?q=' + encodeURIComponent(q), { credentials: 'same-origin' });
          if (res.ok) {
            var json = await res.json();
            apiResults = Array.isArray(json) ? json : (Array.isArray(json.data) ? json.data : (Array.isArray(json.results) ? json.results : []));
          }
        } catch (e) {}
        if (apiResults && apiResults.length) {
          renderResults(apiResults.slice(0, 8));
        } else {
          var localResults = await searchEpracurLocal(q);
          renderResults(localResults);
        }
      } finally {
        searchBtn.disabled = false;
      }
    }

    searchBtn.addEventListener('click', function(){ runGpSearch(); });
    queryEl.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); runGpSearch(); } });
  }

  var bmiBtn = document.getElementById('wm-open-bmi');
  var gpBtn = document.getElementById('wm-open-gp');
  if (bmiBtn) bmiBtn.addEventListener('click', openBmiHelper);
  if (gpBtn) gpBtn.addEventListener('click', openGpHelper);
})();
</script>
