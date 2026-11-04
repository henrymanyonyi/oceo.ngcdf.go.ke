{{-- A builder step that is not open yet (Memos\Compose): step 2 waits for step 1, steps 3 to 6 for the first save. --}}
@props(['message' => 'Opens when you save the draft in step 2.'])
<div class="rounded-xl border border-dashed border-slate-200 bg-white/60 px-5 py-6 text-center">
    <i class="fa-solid fa-lock text-slate-300"></i>
    <p class="text-[13px] text-slate-500 mt-1.5">{{ $message }}</p>
</div>
