<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees which officers on the staff list (November 2026). Enforced on the query, never by
 * hiding a menu: every screen that lists or searches staff goes through here.
 *
 *  - The full directory, browsable and searchable by email: the CEO and the System Administrator
 *    (`staff.directory`), who maintain the HR register.
 *  - Everyone else, in a team picker: search first, by name or PF number (forPicker()); nobody is
 *    listed before they type. The Office of the CEO may also list a department or duty station it chooses.
 *  - A delegated officer preparing a memo (no account): search by name or PF number only.
 */
class StaffVisibility
{
    public const MIN_SEARCH = 2;

    public function seesDirectory(?User $user): bool
    {
        return (bool) $user?->can('staff.directory');
    }

    /** A Head of Department (or a Regional Coordinator later): limited to their own unit. */
    public function isUnitScoped(?User $user): bool
    {
        return $user !== null && ! $this->seesDirectory($user) && ! $user->can('activities.view') && $user->can('memos.originate');
    }

    /**
     * The staff list page.
     *
     * @param  Builder<Staff>  $query
     * @return Builder<Staff>
     */
    public function forList(Builder $query, User $user, ?string $search): Builder
    {
        $term = trim((string) $search);

        if ($this->seesDirectory($user)) {
            return $query->search($term);
        }

        $this->searchNameOrNumber($query, $term);

        if ($this->isUnitScoped($user)) {
            return $query->where(fn (Builder $q) => $this->ownUnit($q, $user)->orWhere('created_by', $user->id));
        }

        // Search only: nothing is listed until a name or PF number is typed.
        return mb_strlen($term) >= self::MIN_SEARCH ? $query : $query->whereRaw('1 = 0');
    }

    /** Whether the list shows nothing until the user searches. */
    public function listNeedsSearch(User $user): bool
    {
        return ! $this->seesDirectory($user) && ! $this->isUnitScoped($user);
    }

    /**
     * Choosing officers for a team, a delegated memo or an account: search first (10 November 2026).
     * Nobody is listed until at least two letters of a name or PF number are typed; then any active
     * officer who matches. Only the CEO and the System Administrator also match by email. $browse lets
     * the Office of the CEO list a department or duty station it has chosen on purpose.
     *
     * @param  Builder<Staff>  $query
     * @return Builder<Staff>
     */
    public function forPicker(Builder $query, ?User $user, ?string $search, bool $browse = false): Builder
    {
        $term = trim((string) $search);

        $this->seesDirectory($user) ? $query->search($term) : $this->searchNameOrNumber($query, $term);

        return mb_strlen($term) >= self::MIN_SEARCH || ($browse && $user?->can('activities.view'))
            ? $query
            : $query->whereRaw('1 = 0');
    }

    /**
     * A Head's department, or a Regional Coordinator's region (the officers at its duty stations).
     *
     * @param  Builder<Staff>  $query
     * @return Builder<Staff>
     */
    private function ownUnit(Builder $query, User $user): Builder
    {
        return $user->isRegionalCoordinator()
            ? $query->whereHas('office', fn (Builder $o) => $o->where('region_id', $user->region_id ?? 0))
            : $query->where('department_id', $user->department_id ?? 0);
    }

    /**
     * @param  Builder<Staff>  $query
     */
    private function searchNameOrNumber(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $query->where(fn (Builder $q) => $q->where('name', 'like', "%{$term}%")->orWhere('staff_number', 'like', "%{$term}%"));
    }
}
