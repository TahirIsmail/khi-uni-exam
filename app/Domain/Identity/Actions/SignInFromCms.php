<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\InvalidCmsTicket;
use App\Domain\Identity\Exceptions\StaffEmailConflict;
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
    public function __construct(
        private readonly CmsTicketVerifier $verifier,
        private readonly LinkCmsStaff $link,
    ) {}

    /**
     * @return array{user: User, redirect: string, branch: int|null, intake: int|null}
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

        try {
            $user = ($this->link)($staff, signingIn: true);
        } catch (StaffEmailConflict) {
            throw new InvalidCmsTicket('email_belongs_to_another_account', $staff->id);
        }

        if (! $user->is_active) {
            throw new InvalidCmsTicket('local_user_inactive', $claims['sub']);
        }

        return ['user' => $user, 'redirect' => $claims['redirect'], 'branch' => $claims['branch'], 'intake' => $claims['intake']];
    }
}
