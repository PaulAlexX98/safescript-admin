{{-- Global client-side listeners for Filament --}}
{{-- Listens for Livewire server -> client event "print-zpl" and downloads a .zpl file --}}
<script>
(function () {
    function handlePrintZpl(payload) {
        try {
            var zpl = (payload && (payload.zpl ?? payload[0]?.zpl ?? payload[0])) || '';
            if (typeof zpl !== 'string' || zpl.length === 0) {
                console.warn('print-zpl received but no payload');
                return;
            }

            // Trigger a visible download so the user knows something happened
            var blob = new Blob([zpl], { type: 'application/zpl' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            var ts = new Date().toISOString().replace(/[:.]/g,'-');
            a.href = url;
            a.download = 'label-' + ts + '.zpl';
            document.body.appendChild(a);
            a.click();
            setTimeout(function () {
                URL.revokeObjectURL(url);
                a.remove();
            }, 0);

            // Best-effort copy to clipboard for quick paste into Zebra tools / Print Server
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(zpl).catch(function(){ /* ignore */ });
            }

            console.log('print-zpl handled in browser, length=', zpl.length);
        } catch (e) {
            console.error('print-zpl handler error', e);
        }
    }

    // Livewire v3 event hookup
    document.addEventListener('livewire:init', function () {
        if (window.Livewire && typeof window.Livewire.on === 'function') {
            window.Livewire.on('print-zpl', handlePrintZpl);
        }
    });

    // Fallback in case the event is dispatched as a DOM CustomEvent
    window.addEventListener('print-zpl', function (e) {
        handlePrintZpl(e.detail || {});
    });
})();
</script>
