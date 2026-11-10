<?php

namespace App\Livewire\Concerns;

use App\Models\Constituency;
use Illuminate\Validation\Rule;

/**
 * A memo's venue and location (10 November 2026): region, county, constituency and venue are all
 * required. Choosing a county fills in its region; choosing a constituency fills in its county and
 * region; on saving, the region is taken from the constituency, so the three always agree. Shared by
 * the memo builder (Memos\Compose), the memo page (Memos\Show) and the Office of the CEO's activity
 * page (Activities\Show), which have the loc* properties.
 */
trait EntersLocations
{
    public function updatedLocCounty(string $value): void
    {
        $this->locConstituency = '';
        if ($value !== '' && $region = Constituency::query()->where('county_id', $value)->value('region_id')) {
            $this->locRegion = (string) $region;
        }
    }

    public function updatedLocConstituency(string $value): void
    {
        if ($value && $c = Constituency::find($value)) {
            $this->locCounty = (string) $c->county_id;
            $this->locRegion = (string) $c->region_id;
        }
    }

    /** @return array<string, list<mixed>> */
    protected function locationRules(): array
    {
        return [
            'locRegion' => ['required', 'exists:regions,id'],
            'locCounty' => ['required', 'exists:counties,id'],
            'locConstituency' => ['required', Rule::exists('constituencies', 'id')->where('county_id', $this->locCounty ?: 0)],
            'locVenue' => ['required', 'string', 'max:255', 'regex:/\S/'],
        ];
    }

    /** @return array<string, string> */
    protected function locationMessages(): array
    {
        return ['locConstituency.exists' => 'Choose a constituency in the county chosen.'];
    }

    /** @return array<string, string> */
    protected function locationAttributes(): array
    {
        return ['locRegion' => 'region', 'locCounty' => 'county', 'locConstituency' => 'constituency', 'locVenue' => 'venue'];
    }

    /**
     * The location as ActivityEditor::addLocation() takes it, the region from the constituency.
     *
     * @return array{region_id: int, county_id: int, constituency_id: int, venue: string}
     */
    protected function locationData(): array
    {
        $constituency = Constituency::query()->findOrFail((int) $this->locConstituency);

        return [
            'region_id' => (int) ($constituency->region_id ?: $this->locRegion),
            'county_id' => (int) $constituency->county_id,
            'constituency_id' => (int) $constituency->id,
            'venue' => trim($this->locVenue),
        ];
    }
}
