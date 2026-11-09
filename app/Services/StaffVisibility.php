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
 *  - A Head of Department: their own department, and the officers they added themselves. To put
 *    someone from another department on a team they search by name or PF number; they never browse
 *    another department.
 *  - The Chief of Staff and the Assistant: no browsing; they find an officer by name or PF number
 *    for the work in hand (teams, position and access changes).
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
            return $query->where(fn (Builder $q) => $q->where('department_id', $user->department_id ?? 0)->orWhere('created_by', $user->id));
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
     * Choosing officers for a team or a delegated memo. Without a search the user sees only their own
     * unit (everyone for the Office of the CEO's team building); with a name or PF number, any active officer.
     *
     * @param  Builder<Staff>  $query
     * @return Builder<Staff>
     */
    public function forPicker(Builder $query, ?User $user, ?string $search): Builder
    {
        $term = trim((string) $search);

        if ($this->seesDirectory($user)) {
            return $query->search($term);
        }

        $this->searchNameOrNumber($query, $term);

        if (mb_strlen($term) >= self::MIN_SEARCH) {
            return $query;
        }
        if ($user?->can('activities.view')) {
            return $query;
        }

        return $this->isUnitScoped($user) ? $query->where('department_id', $user->department_id ?? 0) : $query->whereRaw('1 = 0');
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
