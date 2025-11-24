<script>
  // Listen for Filament/Livewire browser event dispatched from PHP: $this->dispatch('print-zpl', zpl: $zpl)
  window.addEventListener('print-zpl', async (e) => {
    try {
      const zpl = (e?.detail?.zpl ?? '').toString();

      // Basic guards and debugging
      if (!zpl.trim()) {
        console.warn('[ZPL] Empty ZPL payload received', e?.detail);
        alert('No label data received');
        return;
      }
      console.debug('[ZPL] Event received. Length:', zpl.length);

      // If Zebra Browser Print is available, send directly to the default printer
      if (window.BrowserPrint) {
        console.debug('[ZPL] Using BrowserPrint');
        BrowserPrint.getDefaultDevice(
          'printer',
          function (device) {
            if (!device) {
              alert('No Zebra printer detected by BrowserPrint');
              return;
            }
            console.debug('[ZPL] Sending to device:', device?.uid || '(unknown)');
            device.send(
              zpl,
              () => {
                console.info('[ZPL] Print command sent successfully');
                alert('Label sent to printer');
              },
              (err) => {
                console.error('[ZPL] Print failed:', err);
                alert('Print failed: ' + err);
              }
            );
          },
          function () {
            console.warn('[ZPL] BrowserPrint not installed or no default device');
            alert('Browser Print not installed or no default printer detected');
          }
        );
        return;
      }

      // Fallback: download a .zpl file so it can be printed via Zebra utilities
      console.debug('[ZPL] BrowserPrint not found - falling back to download');
      const blob = new Blob([zpl], { type: 'text/plain' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'label.zpl';
      // Ensure element is in DOM for Firefox
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      alert('Label file downloaded (label.zpl). Use Zebra Setup Utilities to print.');
    } catch (err) {
      console.error('[ZPL] Unexpected error handling print-zpl event', err);
      alert('Unexpected error while preparing label: ' + (err?.message || err));
    }
  });
</script>