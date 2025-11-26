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

  var wrap     = document.getElementById(wrapId);
  var startBtn = document.getElementById(startId);
  var statusEl = document.getElementById(statusId);
  if (!startBtn || !statusEl) { if (wrap) wrap.style.display = 'none'; return; }

  var ta = targetId ? document.getElementById(targetId) : null;
  if (!ta) {
    var ctrl = startBtn.getAttribute('aria-controls');
    if (ctrl) ta = document.getElementById(ctrl);
  }
  if (!ta) { if (wrap) wrap.style.display = 'none'; return; }

  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  var isSecure = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
  if (!SR || !isSecure) {
    if (wrap) wrap.style.display = 'none';
    return;
  }

  var rec = new SR();
  rec.lang = 'en-GB';
  rec.continuous = true;
  rec.interimResults = true;

  var active = false;
  var userStopped = false;

  function setStatus(text){ statusEl.textContent = text; }
  function syncHidden(){
    var mirror = document.querySelector('#answers_' + ta.id);
    if (mirror) mirror.value = ta.value;
  }
  function toggleUI(on){
    active = !!on;
    startBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    startBtn.textContent = on ? 'Stop dictation' : 'Start dictation';
    setStatus(on ? 'Listening…' : 'Mic off');
  }

  var interim = '';
  rec.onresult = function(e){
    interim = '';
    var finalDelta = '';
    for (var i = e.resultIndex; i < e.results.length; i++) {
      var r = e.results[i], txt = r[0].transcript || '';
      if (r.isFinal) finalDelta += txt; else interim += txt;
    }
    if (finalDelta) {
      var needsSpace = ta.value && !/\\s$/.test(ta.value);
      ta.value += (needsSpace ? ' ' : '') + finalDelta.trim();
      try { ta.selectionStart = ta.selectionEnd = ta.value.length; } catch(e){}
      syncHidden();
    }
    setStatus(interim ? ('Listening… ' + interim.trim()) : 'Listening…');
  };
  rec.onerror = function(e){ setStatus('Mic error ' + (e.error || '')); };
  rec.onstart = function(){ toggleUI(true); };
  rec.onend = function(){
    toggleUI(false);
    if (!userStopped) { try { rec.start(); } catch(_){} }
  };

  startBtn.addEventListener('click', function(){
    if (!active) { userStopped = false; try { rec.start(); } catch(_) { setStatus('Mic error starting'); } }
    else { userStopped = true; try { rec.stop(); } catch(_){} }
  });

  ta.addEventListener('input', syncHidden);

  // rebind after Livewire modal rerenders
  document.addEventListener('livewire:navigated', function(){
    if (!document.getElementById(startId)) return;
  });
})();
</script>

<style>
  .voice-btn{appearance:none;border:0;border-radius:999px;padding:8px 14px;font-weight:600;cursor:pointer;background:rgba(34,197,94,.15)}
  .voice-btn[aria-pressed=\"true\"]{background:rgba(239,68,68,.18)}
</style>