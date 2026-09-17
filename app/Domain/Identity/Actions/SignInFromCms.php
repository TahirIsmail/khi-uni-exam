<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\InvalidCmsTicket;
use App\Domain\Identity\Models\CmsStaff;
use App\Domain\Identity\Sso\CmsTicketVerifier;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a verified kmu-cms ticket into a local user (docs/architecture/adr-0002-sso-from-cms.md).
 *
 * Order matters: the ticket id is burned before anything else, so a refused or copied ticket can
 * never be retried; the CMS staff record is re-read (the ticket is trusted for the id only); the
 * local user is found by cms_staff_id, or created on first visit with name and email from the CMS.
 */
final class SignInFromCms
{
    public function __construct(private readonly CmsTicketVerifier $verifier) {}

    /**
     * @return array{user: User, redirect: string}
     */
    public function __invoke(string $ticket): array
    {
        $claims = $this->verifier->verify($ticket, time());

        try {
            DB::table('sso_consumed_tickets')->insert([
                'jti' => $claims['jti'],
                'cms_staff_id' => $claims['sub'],
                'expires_at' => Carbon::createFromTimestamp($claims['exp']),
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new InvalidCmsTicket('replayed', $claims['sub']);
        }

        $staff = CmsStaff::query()->find($claims['sub']);
        if ($staff === null) {
            throw new InvalidCmsTicket('unknown_staff', $claims['sub']);
        }
        if (! $staff->is_active) {
            throw new InvalidCmsTicket('cms_staff_inactive', $claims['sub']);
        }

        $user = DB::transaction(fn () => $this->linkedUser($staff));

        if (! $user->is_active) {
            throw new InvalidCmsTicket('local_user_inactive', $claims['sub']);
        }

        return ['user' => $user, 'redirect' => $claims['redirect']];
    }

    private function linkedUser(CmsStaff $staff): User
    {
        $email = mb_strtolower(trim($staff->email));
        $user = User::query()->where('cms_staff_id', $staff->id)->lockForUpdate()->first();

        // An existing local account with this email that is not linked to this staff member is
        // never taken over automatically; an administrator has to resolve it.
        $emailOwner = User::query()->where('email', $email)->first();
        if ($emailOwner !== null && (int) $emailOwner->cms_staff_id !== (int) $staff->id) {
            throw new InvalidCmsTicket('email_belongs_to_another_account', $staff->id);
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
            'last_login_at' => now(),
        ])->save();

        return $user;
    }
}
