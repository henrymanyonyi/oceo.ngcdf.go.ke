@use('App\Support\Ui')
    @unless ($editable)
        <div class="px-4 py-3 rounded-lg text-sm bg-slate-100 border border-slate-200 text-slate-600"><i class="fa-solid fa-lock mr-2"></i>The memo is with {{ $a->currentHolder() }}. The team and costs can be changed again only if it is returned to you.</div>
    @endunless
    @if ($a->locations->isEmpty() || $a->locations->whereNotNull('county_id')->isEmpty())
        <div class="px-4 py-3 rounded-lg text-sm bg-amber-50 border border-amber-200 text-amber-800"><i class="fa-solid fa-circle-info mr-2"></i>Allowances (DSA) are worked out from the county of the activity. {{ $embedded ? 'Add a county in step 3, Venue and location, so DSA can be calculated.' : 'Add a county to the location on the memo page so DSA can be calculated.' }}</div>
    @endif
