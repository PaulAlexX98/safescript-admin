<div>
    <style>
        .sig-wrap{border:1px solid #e5e7eb;border-radius:.5rem;padding:.5rem;background:#fff}
        .sig-canvas{width:100%;height:220px;touch-action:none;display:block;background:#fafafa;border-radius:.375rem}
        .sig-tools{margin-top:.5rem;display:flex;gap:.5rem}
        .sig-tools button{padding:.4rem .7rem;border:1px solid #e5e7eb;border-radius:.375rem;background:#f9fafb;cursor:pointer}
    </style>
    <div class="sig-wrap">
        <canvas class="sig-canvas" width="800" height="300"></canvas>
        <div class="sig-tools">
            <button type="button" class="sig-clear">Clear</button>
        </div>
    </div>
    <script>
        (function(){
            const wrap = document.currentScript.previousElementSibling.closest('.sig-wrap');
            const canvas = wrap.querySelector('.sig-canvas');
            const clearBtn = wrap.querySelector('.sig-clear');
            const ctx = canvas.getContext('2d');
            let drawing = false, last = null;
            const ratio = window.devicePixelRatio || 1;
            function resize(){
                const rect = canvas.getBoundingClientRect();
                canvas.width = Math.round(rect.width * ratio);
                canvas.height = Math.round(300 * ratio);
                ctx.setTransform(ratio,0,0,ratio,0,0);
            }
            resize();
            window.addEventListener('resize', resize);
            const getPos = (e) => {
                const rect = canvas.getBoundingClientRect();
                const client = e.touches && e.touches[0] ? e.touches[0] : e;
                return { x: client.clientX - rect.left, y: client.clientY - rect.top };
            };
            const start = (e) => { drawing = true; last = getPos(e); e.preventDefault(); };
            const move = (e) => {
                if (!drawing) return; const pos = getPos(e);
                ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#111827';
                ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(pos.x, pos.y); ctx.stroke();
                last = pos; e.preventDefault();
            };
            const end = () => { drawing = false; last = null; };
            canvas.addEventListener('mousedown', start);
            canvas.addEventListener('mousemove', move);
            window.addEventListener('mouseup', end);
            canvas.addEventListener('touchstart', start, { passive:false });
            canvas.addEventListener('touchmove', move, { passive:false });
            canvas.addEventListener('touchend', end);
            clearBtn.addEventListener('click', () => ctx.clearRect(0,0,canvas.width,canvas.height));
        })();
    </script>
</div>