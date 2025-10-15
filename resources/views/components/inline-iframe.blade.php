@props(['src', 'height' => '75vh'])

{{-- Kill modal padding/white background inside Filament modals --}}
<style>
  .fi-modal-window .fi-modal-content { padding: 0 !important; background: #0b0b0b !important; }
  .fi-modal-window .fi-modal-body { padding: 0 !important; }
  .fi-modal-window { background: transparent !important; }
</style>

<div style="margin:0; background:#0b0b0b; border-radius:0.5rem; overflow:hidden;">
    <iframe
        src="{{ $src }}"
        style="display:block; width:100%; height: {{ $height }}; border:0; background:transparent; color:inherit;"
        loading="eager"
        referrerpolicy="no-referrer"
        onload="try{const d=this.contentDocument||this.contentWindow?.document; if(d){ d.documentElement.style.background='#0b0b0b'; d.body.style.background='#0b0b0b'; d.body.style.color='#e5e7eb'; }}catch(e){}"
    ></iframe>
</div>