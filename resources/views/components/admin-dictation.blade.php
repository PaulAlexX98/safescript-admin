{{-- reusable mic toolbar --}}
@php
  $uid = $uid ?? 'v' . substr(md5(uniqid('', true)), 0, 8);
  $startId  = $startId  ?? ($uid . '_start');
  $statusId = $statusId ?? ($uid . '_status');
  $target   = $target   ?? null;
  $wrapId   = $uid . '_wrap';
@endphp

<div id="{{ $wrapId }}" class="voice-toolbar" style="display:flex;align-items:center;gap:10px;margin-top:10px">
  <button type="button"
          id="{{ $startId }}"
          class="voice-btn"
          aria-pressed="false"
          @if($target) aria-controls="{{ $target }}" @endif>
    Start dictation
  </button>
  <span id="{{ $statusId }}" class="voice-status" style="font-size:12px;opacity:.85;display:inline-flex;align-items:center;gap:6px">
    <i class="voice-dot" style="width:8px;height:8px;border-radius:999px;background:#9ca3af;display:inline-block"></i>
    Mic off
  </span>
</div>

<script>
(function(){
  var startId  = @json($startId);
  var statusId = @json($statusId);
  var targetId = @json($target);
  var wrapId   = @json($wrapId);

  function setStatus(el, text){ if (el) el.textContent = text; }

  function bind(){
    var wrap     = document.getElementById(wrapId);
    var startBtn = document.getElementById(startId);
    var statusEl = document.getElementById(statusId);
    if (!startBtn || !statusEl) { if (wrap) wrap.style.display = 'none'; return; }
    if (startBtn.dataset.bound === '1') return;

    // Resolve target
    var ta = targetId ? document.getElementById(targetId) : null;
    if (!ta){
      var ctrl = startBtn.getAttribute('aria-controls');
      if (ctrl) ta = document.getElementById(ctrl);
      if (!ta && wrap && wrap.previousElementSibling && wrap.previousElementSibling.tagName === 'TEXTAREA') {
        ta = wrap.previousElementSibling;
      }
    }
    if (!ta) { setStatus(statusEl, 'No target field found'); return; }

    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    var isSecure = (location.protocol === 'https:') || ['localhost','127.0.0.1'].includes(location.hostname);
    if (!SR) { setStatus(statusEl, 'SpeechRecognition not supported'); startBtn.disabled = true; return; }
    if (!isSecure) { setStatus(statusEl, 'Needs https or localhost'); startBtn.disabled = true; return; }

    var rec = new SR();
    rec.lang = 'en-GB';
    rec.continuous = true;
    rec.interimResults = true;

    // expose on window for manual stop if needed
    window.__dictation = window.__dictation || {};
    window.__dictation[startId] = rec;

    var active = false, userStopped = false, interim = '';
    function toggleUI(on){
      active = !!on;
      startBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      startBtn.textContent = on ? 'Stop dictation' : 'Start dictation';
      setStatus(statusEl, on ? 'Listening…' : 'Mic off');
    }

    function syncHidden(){
      var mirror = document.querySelector('#answers_' + (ta.id || ''));
      if (mirror) mirror.value = ta.value;
    }

    rec.onresult = function(e){
      interim = '';
      var finalDelta = '';
      for (var i = e.resultIndex; i < e.results.length; i++) {
        var r = e.results[i];
        var txt = (r[0] && r[0].transcript) ? r[0].transcript : '';
        if (r.isFinal) finalDelta += txt; else interim += txt;
      }
      if (finalDelta) {
        var needsSpace = ta.value && !/\s$/.test(ta.value);
        ta.value += (needsSpace ? ' ' : '') + finalDelta.trim();
        try { ta.selectionStart = ta.selectionEnd = ta.value.length; } catch(e){}
        syncHidden();
      }
      setStatus(statusEl, interim ? ('Listening… ' + interim.trim()) : 'Listening…');
    };

    rec.onerror = function(e){ setStatus(statusEl, 'Mic error ' + ((e && e.error) ? e.error : '')); console.warn('dictation error', e); };
    rec.onstart = function(){ toggleUI(true); };
    rec.onend   = function(){ toggleUI(false); if (!userStopped) { try { rec.start(); } catch(err) { console.warn('restart failed', err); } } };

    async function ensurePermission(){
      try {
        const s = await navigator.mediaDevices.getUserMedia({ audio: true });
        if (s && s.getTracks) s.getTracks().forEach(t => t.stop());
        return true;
      } catch(e){
        console.warn('getUserMedia failed', e);
        setStatus(statusEl, 'Mic blocked or denied');
        return false;
      }
    }

    startBtn.addEventListener('click', async function(){
      if (!active) {
        userStopped = false;
        if (!(await ensurePermission())) return; // force prompt then start
        try { rec.start(); } catch(err) { setStatus(statusEl, 'Mic error starting'); console.warn('start error', err); }
      } else {
        userStopped = true;
        try { rec.stop(); } catch(err) {}
      }
    });

    ta.addEventListener('input', syncHidden);

    startBtn.dataset.bound = '1';
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind, { once:true });
  else bind();
  document.addEventListener('livewire:navigated', bind);
  document.addEventListener('filament:modal.opened', bind);
})();
</script>

<style>
  .voice-btn{appearance:none;border:0;border-radius:999px;padding:8px 14px;font-weight:600;cursor:pointer;background:rgba(34,197,94,.15)}
  .voice-btn[aria-pressed="true"]{background:rgba(239,68,68,.18)}
</style>