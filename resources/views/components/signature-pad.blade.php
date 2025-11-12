<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <style>
        .sig-wrap{border:1px solid #e5e7eb;border-radius:.5rem;padding:.5rem;background:#fff}
        .sig-canvas{width:100%;height:220px;touch-action:none;display:block;background:#fafafa;border-radius:.375rem;cursor:crosshair}
        .sig-tools{margin-top:.5rem;display:flex;gap:.5rem;align-items:center}
        .sig-tools button{padding:.4rem .7rem;border:1px solid #e5e7eb;border-radius:.375rem;background:#f9fafb;cursor:pointer}
        .sig-tools .hint{font-size:.85rem;color:#6b7280}
    </style>

    <div
        class="sig-wrap"
        x-data="signaturePad({ value: @entangle($getStatePath()).defer, statePath: @js($getStatePath()) })"
    >
        <canvas x-ref="canvas" class="sig-canvas"></canvas>

        <div class="sig-tools">
            <button type="button" x-on:click="clear()">Clear</button>
            <button type="button" x-on:click="save()">Save signature</button>
            <span class="hint" x-show="value">Autosaved ✓</span>
        </div>

        <!-- Keep Livewire state in sync even if Alpine loses focus -->
        <input type="hidden" x-model="value">
    </div>

    @once
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('signaturePad', ({ value, statePath }) => ({
                value,
                statePath,
                canvas: null,
                ctx: null,
                drawing: false,
                last: null,
                ratio: window.devicePixelRatio || 1,

                init() {
                    this.canvas = this.$refs.canvas;
                    this.ctx = this.canvas.getContext('2d');

                    const resize = () => {
                        const rect = this.canvas.getBoundingClientRect();
                        // Scale by device pixel ratio for crisp lines
                        this.canvas.width  = Math.max(300, Math.round(rect.width * this.ratio));
                        this.canvas.height = Math.round(220 * this.ratio);
                        this.ctx.setTransform(this.ratio, 0, 0, this.ratio, 0, 0);

                        // If we already have a saved signature, render it
                        if (this.value) this.drawImage(this.value);
                    };
                    resize();
                    window.addEventListener('resize', resize);

                    const pos = (e) => {
                        const r = this.canvas.getBoundingClientRect();
                        const c = e.touches?.[0] ?? e;
                        return { x: c.clientX - r.left, y: c.clientY - r.top };
                    };

                    const start = (e) => { this.drawing = true; this.last = pos(e); e.preventDefault(); };
                    const move  = (e) => {
                        if (!this.drawing) return;
                        const p = pos(e);
                        this.ctx.lineWidth = 2;
                        this.ctx.lineCap = 'round';
                        this.ctx.strokeStyle = '#111827';
                        this.ctx.beginPath();
                        this.ctx.moveTo(this.last.x, this.last.y);
                        this.ctx.lineTo(p.x, p.y);
                        this.ctx.stroke();
                        this.last = p;
                        e.preventDefault();
                    };
                    const end = () => {
                        if (!this.drawing) return;
                        this.drawing = false;
                        this.last = null;
                        // Auto-capture the drawing so users don't need to press "Save"
                        if (!this.isBlank()) this.save();
                    };

                    this.canvas.addEventListener('mousedown', start);
                    this.canvas.addEventListener('mousemove', move);
                    window.addEventListener('mouseup', end);

                    this.canvas.addEventListener('touchstart', start, { passive: false });
                    this.canvas.addEventListener('touchmove',  move,  { passive: false });
                    this.canvas.addEventListener('touchend',   end);

                    this.$watch('value', () => this.sync());

                    // Initial paint if value exists
                    if (this.value) this.drawImage(this.value);
                },

                sync() {
                    try {
                        // Explicitly sync to Livewire in case entangle misses updates
                        if (this.statePath && this.$wire?.set) {
                            this.$wire.set(this.statePath, this.value);
                        }
                    } catch (_) {}
                },

                clear() {
                    this.ctx?.clearRect(0, 0, this.canvas.width, this.canvas.height);
                    this.value = null;
                    this.sync();
                },

                save() {
                    // Paint a white background so it looks right in PDFs
                    const tmp = document.createElement('canvas');
                    tmp.width = this.canvas.width;
                    tmp.height = this.canvas.height;
                    const tctx = tmp.getContext('2d');
                    tctx.fillStyle = '#fff';
                    tctx.fillRect(0, 0, tmp.width, tmp.height);
                    tctx.drawImage(this.canvas, 0, 0);
                    this.value = tmp.toDataURL('image/png');
                    this.sync();
                },

                isBlank() {
                    if (!this.canvas || !this.ctx) return true;
                    const { width, height } = this.canvas;
                    const data = this.ctx.getImageData(0, 0, width, height).data;
                    for (let i = 3; i < data.length; i += 4) {
                        if (data[i] !== 0) return false; // any non‑transparent pixel
                    }
                    return true;
                },

                normaliseSrc(src) {
                    if (!src) return '';
                    const s = String(src);
                    if (s.startsWith('data:')) return s;                // data URL
                    if (s.startsWith('http://') || s.startsWith('https://')) return s; // absolute URL
                    if (s.startsWith('/')) return s;                     // already absolute path
                    // Treat plain storage path like "signatures/xyz.png"
                    return '/storage/' + s.replace(/^storage\//, '');
                },

                drawImage(src) {
                    try {
                        const img = new Image();
                        img.crossOrigin = 'anonymous';
                        img.onload = () => {
                            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
                            this.ctx.drawImage(img, 0, 0, this.canvas.width, this.canvas.height);
                        };
                        img.src = this.normaliseSrc(src);
                    } catch (_) {}
                },
            }))
        });
    </script>
    @endonce
</x-dynamic-component>