<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Region;
use App\Models\Staff;
use App\Models\User;
use App\Support\MobileNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates one of the accounts in User::ROLES (FRD CF-01) with a one-time random
 * password, printed once. Two-factor authentication must be set up at first
 * sign-in (CF-03). Refuses a second active account for the same role (for a
 * Head of Department, the same department; for a Regional Coordinator, the same region).
 */
class CreateUser extends Command
{
    protected $signature = 'activities:create-user
        {role : ceo, chief_of_staff, assistant_chief_of_staff, admin, hod or regional_coordinator}
        {email : Sign-in email address}
        {name : Full name}
        {--department= : For hod and regional_coordinator: the department name, e.g. ICT}
        {--region= : For regional_coordinator: the region code or name, e.g. CST or "Coast Region"}
        {--staff= : PF number of the officer on the staff list; their mobile number is picked up}
        {--phone= : Mobile number for the action-needed SMS, e.g. 0712345678}';

    protected $description = 'Create the CEO, Chief of Staff, Assistant Chief of Staff, System Administrator, a Head of Department or a Regional Coordinator account';

    public function handle(): int
    {
        $data = [
            'role' => strtolower((string) $this->argument('role')),
            'email' => strtolower(trim((string) $this->argument('email'))),
            'name' => trim((string) $this->argument('name')),
        ];

        $validator = Validator::make($data, [
            'role' => ['required', 'in:'.implode(',', array_keys(User::ROLES))],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $department = null;
        $region = null;
        if (in_array($data['role'], User::REGIONAL_ROLES, true)) {
            $given = trim((string) $this->option('region'));
            $region = $given === '' ? null : Region::query()->where('code', strtoupper($given))->orWhere('name', $given)->first();
            if (! $region) {
                $this->error('Give the region with --region=CODE or --region="Name": '.Region::query()->orderBy('name')->get()->map(fn (Region $r) => "{$r->code} ({$r->name})")->implode(', ').'.');

                return self::FAILURE;
            }
        }
        if (in_array($data['role'], [...User::DEPARTMENTAL_ROLES, ...User::REGIONAL_ROLES], true)) {
            $department = Department::query()->where('name', trim((string) $this->option('department')))->first();

            if (! $department) {
                $this->error('Give the department with --department="Name" (as listed in Settings › Departments).');

                return self::FAILURE;
            }
        }

        // One per department for a Head, one per region for a Regional Coordinator, one in total otherwise.
        $exists = User::query()->role($data['role'])->where('is_active', true)
            ->when($region, fn ($q) => $q->where('region_id', $region->id), fn ($q) => $q->when($department, fn ($q) => $q->where('department_id', $department->id)))
            ->exists();
        if ($exists) {
            $this->error('An active '.User::ROLES[$data['role']].' account already exists'.($region ? " for {$region->name}" : ($department ? " for {$department->name}" : '')).'. Deactivate it first (CF-04).');

            return self::FAILURE;
        }

        $staff = null;
        if (filled($this->option('staff'))) {
            $staff = Staff::query()->where('staff_number', strtoupper(trim((string) $this->option('staff'))))->first();
            if (! $staff) {
                $this->error('No officer on the staff list has PF number '.$this->option('staff').'.');

                return self::FAILURE;
            }
            if (User::query()->where('staff_id', $staff->id)->exists()) {
                $this->error("{$staff->name} already has an account.");

                return self::FAILURE;
            }
        }

        $phone = filled($this->option('phone')) ? MobileNumber::normalise((string) $this->option('phone')) : MobileNumber::normalise($staff?->phone);
        if (filled($this->option('phone')) && $phone === null) {
            $this->error('Give a Kenyan mobile number with --phone, e.g. 0712345678.');

            return self::FAILURE;
        }

        $password = Str::password(16);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $phone,
            'staff_id' => $staff?->id,
            'department_id' => $department?->id,
            'region_id' => $region?->id,
            'password' => $password,
            'is_active' => true,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole($data['role']);

        $this->info("Created {$user->roleLabel()} account for {$user->email}.");
        $this->line("One-time password: {$password}");
        $this->warn('Share it privately. Two-factor authentication must be set up at first sign-in.');

        return self::SUCCESS;
    }
}
