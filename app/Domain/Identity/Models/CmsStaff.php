<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A staff member in kmu-cms, read through the v_cms_staff view on the read-only `cms` connection.
 * This app never writes CMS data; saving or deleting throws.
 *
 * @property int $id
 * @property string $employee_id
 * @property string $name
 * @property string $surname
 * @property string $email
 * @property int $is_active
 * @property int|null $branch_id
 */
final class CmsStaff extends Model
{
    protected $connection = 'cms';

    protected $table = 'v_cms_staff';

    public $timestamps = false;

    public $incrementing = false;

    protected static function booted(): void
    {
        $refuse = static function (): never {
            throw new LogicException('kmu-cms data is read-only in this application.');
        };

        self::saving($refuse);
        self::deleting($refuse);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function fullName(): string
    {
        return trim($this->name.' '.$this->surname);
    }
}
