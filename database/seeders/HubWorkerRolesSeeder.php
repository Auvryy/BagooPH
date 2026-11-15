<?php

namespace Database\Seeders;

use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Additive seeder that ensures each active hub has dedicated single-role workers
 * (Entry / Intake and Pack & Dispatch) ready for web & mobile login.
 */
class HubWorkerRolesSeeder extends Seeder
{
    public function run(): void
    {
        $hubs = LogisticsHub::eligible()->with('company')->orderBy('id')->get();
        if ($hubs->isEmpty()) {
            return;
        }

        $passwordHash = Hash::make('Password1234');
        $companyOwners = LogisticsCompany::pluck('user_id', 'id');

        DB::transaction(function () use ($hubs, $passwordHash, $companyOwners): void {
            // Preserve existing operators as full-operations unless already assigned a specific role.
            HubHandler::query()->whereNull('job_role')->update(['job_role' => HubHandler::JOB_ROLE_ALL]);

            foreach ($hubs as $hub) {
                $slug = strtolower($hub->code);
                $ownerId = $companyOwners[$hub->logistics_company_id] ?? null;

                foreach ([
                    HubHandler::JOB_ROLE_INTAKE => [
                        'suffix' => 'intake',
                        'label' => 'Intake Worker',
                    ],
                    HubHandler::JOB_ROLE_PACK_DISPATCH => [
                        'suffix' => 'dispatcher',
                        'label' => 'Pack & Dispatch Worker',
                    ],
                ] as $jobRole => $meta) {
                    $email = "{$slug}.{$meta['suffix']}@bagoo.test";
                    $user = User::firstOrCreate(
                        ['email' => $email, 'role' => 'logistics'],
                        [
                            'name' => "{$hub->name} {$meta['label']}",
                            'email_verified_at' => now(),
                            'password' => $passwordHash,
                            'password_login_enabled' => true,
                            'must_change_password' => false,
                            'role' => 'logistics',
                            'status' => 'active',
                            'kyc_status' => 'approved',
                            'kyc_reviewed_at' => now(),
                            'city' => $hub->city_municipality,
                            'province' => $hub->province,
                        ]
                    );

                    if ($user->wasRecentlyCreated === false && ! $user->password_login_enabled) {
                        $user->forceFill(['password_login_enabled' => true])->save();
                    }
                    if ($ownerId && $user->provisioned_company_id === null) {
                        $user->forceFill([
                            'provisioned_company_id' => $hub->logistics_company_id,
                            'provisioned_by_id' => $ownerId,
                        ])->save();
                    }

                    HubHandler::updateOrCreate(
                        ['user_id' => $user->id, 'hub_id' => $hub->id],
                        [
                            'logistics_company_id' => $hub->logistics_company_id,
                            'job_role' => $jobRole,
                            'role_title' => HubHandler::roleTitleFor($jobRole),
                            'is_active' => true,
                        ]
                    );

                    if ($ownerId && ! DB::table('company_staff_provisions')->where('user_id', $user->id)->exists()) {
                        DB::table('company_staff_provisions')->insert([
                            'logistics_company_id' => $hub->logistics_company_id,
                            'user_id' => $user->id,
                            'actor_id' => $ownerId,
                            'hub_id' => $hub->id,
                            'kind' => 'operator',
                            'accepted_at' => now(),
                            'created_at' => now(),
                        ]);
                    }
                }
            }
        });
    }
}
