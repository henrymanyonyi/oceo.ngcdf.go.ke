<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Department;
use App\Models\Region;
use App\Support\FinancialYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Automatic memo references (November 2026). A memo is numbered once, on its first formal
 * submission, from its department's sequence for the financial year of that day; the number
 * then stays with it for good, through returns and resubmissions. Each sequence row is locked
 * while its next number is taken, and activities.memo_number is unique, so two memos submitted
 * at once never share a number. Default format follows the references departments already type:
 * NG-CDF BOARD/ICT/001/2026-27; a region's memos have their own sequence, NG-CDF BOARD/RC/CST/001/2026-27.
 */
class MemoNumbering
{
    public const DEFAULT_FORMAT = 'NG-CDF BOARD/{unit}/{seq}/{fy}';

    public function __construct(private AppSettings $settings) {}

    /** The memo's number, assigning the next one in its sequence if it has none. Null when it has no department. */
    public function assign(Activity $activity): ?string
    {
        return DB::transaction(function () use ($activity): ?string {
            $row = Activity::query()->whereKey($activity->id)->lockForUpdate()->toBase()->first(['memo_number', 'organising_department_id', 'originating_region_id']);

            if ($row->memo_number) {
                return $activity->memo_number = $row->memo_number;
            }
            // A Regional Coordinator's memo is numbered in its region's own sequence, apart from its department's.
            if ($row->originating_region_id) {
                $region = Region::query()->findOrFail($row->originating_region_id);
                [$scope, $scopeId, $unit] = ['region', $region->id, self::regionCode($region)];
            } elseif ($row->organising_department_id) {
                $department = Department::query()->findOrFail($row->organising_department_id);
                [$scope, $scopeId, $unit] = ['department', $department->id, self::unitCode($department)];
            } else {
                return null;
            }

            $fy = FinancialYear::for(today())->shortLabel();
            $key = ['scope_type' => $scope, 'scope_id' => $scopeId, 'financial_year' => $fy];

            DB::table('memo_sequences')->insertOrIgnore($key + ['last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $sequence = DB::table('memo_sequences')->where($key)->lockForUpdate()->first();
            $next = (int) $sequence->last_number + 1;
            DB::table('memo_sequences')->where('id', $sequence->id)->update(['last_number' => $next, 'updated_at' => now()]);

            $number = $this->format($unit, $next, $fy);
            $activity->forceFill(['memo_number' => $number, 'memo_numbered_at' => now()])->save();

            return $number;
        });
    }

    public function format(string $unit, int $sequence, string $financialYear): string
    {
        $format = (string) $this->settings->get('memo_reference_format');
        $format = str_contains($format, '{seq}') ? $format : self::DEFAULT_FORMAT;

        return strtr($format, ['{unit}' => $unit, '{seq}' => str_pad((string) $sequence, 3, '0', STR_PAD_LEFT), '{fy}' => $financialYear]);
    }

    /** A region's unit in the reference: "RC/CST" (Regional Coordinator, Coast), from the region's code, else its initials. */
    public static function regionCode(Region $region): string
    {
        $code = filled($region->code) ? Str::upper(trim($region->code)) : collect(preg_split('/\s+/', str_ireplace(' Region', '', $region->name)))->map(fn (string $w) => Str::upper($w[0]))->implode('');

        return 'RC/'.$code;
    }

    /** The department's code, else its initials with acronyms kept whole: "Office of the CEO" → OCEO, "Human Resources" → HR. */
    public static function unitCode(Department $department): string
    {
        if (filled($department->code)) {
            return Str::upper(trim($department->code));
        }

        return collect(preg_split('/[\s,&\-\/]+/', $department->name, flags: PREG_SPLIT_NO_EMPTY))
            ->reject(fn (string $word) => in_array(Str::lower($word), ['of', 'the', 'and'], true))
            ->map(fn (string $word) => $word === Str::upper($word) ? $word : Str::upper($word[0]))
            ->implode('');
    }
}
