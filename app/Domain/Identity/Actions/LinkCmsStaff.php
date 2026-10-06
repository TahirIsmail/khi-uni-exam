<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\StaffEmailConflict;
use App\Domain\Identity\Models\CmsStaff;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The local user for a kmu-cms staff member: found by cms_staff_id, or created with name and email
 * from the CMS. Used by the SSO sign-in and when an administrator sets up a staff member's scopes
 * before their first visit.
 */
final class LinkCmsStaff
{
    /**
     * @throws StaffEmailConflict
     */
    public function __invoke(CmsStaff $staff, bool $signingIn = false): User
    {
        return DB::transaction(function () use ($staff, $signingIn): User {
            $email = mb_strtolower(trim($staff->email));
            $user = User::query()->where('cms_staff_id', $staff->id)->lockForUpdate()->first();

            $emailOwner = User::query()->where('email', $email)->first();
            if ($emailOwner !== null && (int) $emailOwner->cms_staff_id !== (int) $staff->id) {
                throw new StaffEmailConflict("Email of CMS staff {$staff->id} belongs to another account.");
            }

            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'cms_staff_id' => $staff->id,
                    'password' => null,
                    'email_verified_at' => now(),
                ]);
            }

            $user->forceFill([
                'name' => $staff->fullName() !== '' ? $staff->fullName() : $email,
                'email' => $email,
            ]);
            if ($signingIn) {
                $user->forceFill(['last_login_at' => now()]);
            }
            $user->save();

            return $user;
        });
    }
}
