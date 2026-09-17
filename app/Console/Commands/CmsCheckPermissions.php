<?php

namespace App\Console\Commands;

use App\Domain\Identity\Authorization\Permissions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('cms:check-permissions')]
#[Description('Confirm that every kmu-cms permission checkbox this app relies on exists (Roles → Assign Permission → Question Bank & Exams)')]
final class CmsCheckPermissions extends Command
{
    public function handle(): int
    {
        $categories = DB::connection('cms')->table('v_cms_permission_categories')->get()->keyBy('category');
        $problems = [];

        foreach (Permissions::cmsCheckboxes() as $category => $checkboxes) {
            $row = $categories->get($category);
            if ($row === null) {
                $problems[] = "Category {$category} is missing (run the kmu-cms migrations: php index.php kmuschema migrate yes).";

                continue;
            }
            foreach ($checkboxes as $checkbox) {
                if ((int) $row->{'enable_'.$checkbox} !== 1) {
                    $problems[] = "Category {$category} has no '{$checkbox}' checkbox.";
                }
            }
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        $this->info('All '.count(Permissions::codes()).' permissions map to kmu-cms checkboxes.');

        return self::SUCCESS;
    }
}
