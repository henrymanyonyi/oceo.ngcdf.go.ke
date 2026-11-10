@use('App\Support\Ui')
{{--
    The signature pad (App\Services\SignatureRegistry): the officer draws their signature with a mouse,
    a finger or a pen. The drawing is put in the Livewire property named by `model` as a PNG data URI
    (deferred, sent with the next action); the server checks the pixels and keeps a clean copy. Nothing
    is uploaded. The canvas is SignatureRegistry::WIDTH × HEIGHT pixels, shown at the width available.
--}}
@props(['model', 'id' => 'sig-pad'])
<div wire:ignore
    x-data="{
        drawing: false,
        strokes: 0,
        last: null,
        ctx: null,
        init() {
            this.ctx = this.$refs.canvas.getContext('2d');
            this.ctx.lineWidth = 5;
            this.ctx.lineCap = 'round';
            this.ctx.lineJoin = 'round';
            this.ctx.strokeStyle = '#0b1f4d';
        },
        point(e) {
            const r = this.$refs.canvas.getBoundingClientRect();
            return { x: (e.clientX - r.left) * (this.$refs.canvas.width / r.width), y: (e.clientY - r.top) * (this.$refs.canvas.height / r.height) };
        },
        start(e) {
            e.preventDefault();
            this.$refs.canvas.setPointerCapture(e.pointerId);
            this.drawing = true;
            this.last = this.point(e);
            this.ctx.beginPath();
            this.ctx.arc(this.last.x, this.last.y, 2, 0, Math.PI * 2);
            this.ctx.fillStyle = this.ctx.strokeStyle;
            this.ctx.fill();
        },
        move(e) {
            if (! this.drawing) return;
            const p = this.point(e);
            const mid = { x: (this.last.x + p.x) / 2, y: (this.last.y + p.y) / 2 };
            this.ctx.beginPath();
            this.ctx.moveTo(this.last.x, this.last.y);
            this.ctx.quadraticCurveTo(this.last.x, this.last.y, mid.x, mid.y);
            this.ctx.lineTo(p.x, p.y);
            this.ctx.stroke();
            this.last = p;
        },
        end() {
            if (! this.drawing) return;
            this.drawing = false;
            this.strokes++;
            $wire.set(@js($model), this.$refs.canvas.toDataURL('image/png'), false);
        },
        clear() {
            this.ctx.clearRect(0, 0, this.$refs.canvas.width, this.$refs.canvas.height);
            this.strokes = 0;
            $wire.set(@js($model), '', false);
        },
    }"
    class="space-y-1.5">
    <div class="relative rounded-lg border-2 border-dashed border-slate-300 bg-white">
        <canvas id="{{ $id }}" x-ref="canvas" width="{{ \App\Services\SignatureRegistry::WIDTH }}" height="{{ \App\Services\SignatureRegistry::HEIGHT }}"
            class="block w-full aspect-[3/1] touch-none cursor-crosshair rounded-lg"
            aria-label="Signature pad: draw your signature"
            @pointerdown="start($event)" @pointermove="move($event)" @pointerup="end()" @pointercancel="end()"></canvas>
        <div class="pointer-events-none absolute left-6 right-6 bottom-[22%] border-b border-slate-200"></div>
        <p x-show="strokes === 0" class="pointer-events-none absolute inset-0 flex items-center justify-center text-[13px] text-slate-400">Sign here with your mouse, finger or pen</p>
    </div>
    <div class="flex items-center justify-between">
        <p class="text-[11px] text-slate-400">Sign as you do on paper, across the pad.</p>
        <button type="button" @click="clear()" class="{{ Ui::BTN_TINT }}"><i class="fa-solid fa-eraser text-[9px]"></i>Clear</button>
    </div>
</div>
