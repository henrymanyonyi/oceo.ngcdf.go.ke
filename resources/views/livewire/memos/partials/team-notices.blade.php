@use('App\Support\Ui')
    @unless ($editable)
        <div class="px-4 py-3 rounded-lg text-sm bg-slate-100 border border-slate-200 text-slate-600"><i class="fa-solid fa-lock mr-2"></i>The memo is with {{ $a->currentHolder() }}. The team and costs can be changed again only if it is returned to you.</div>
    @endunless
    @if ($a->locations->isEmpty())
        <div class="px-4 py-3 rounded-lg text-sm bg-amber-50 border border-amber-200 text-amber-800"><i class="fa-solid fa-circle-info mr-2"></i>Allowances (DSA) are each officer's standard rate for their job grade × the payable nights of the activity. They are calculated once the activity has its venue and location: {{ $embedded ? 'add it in step 3, Venue and location.' : 'add it on the memo page.' }}</div>
    @endif
