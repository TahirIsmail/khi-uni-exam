<?php

namespace App\Domain\QuestionBank\Models;

use App\Domain\QuestionBank\Enums\VersionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Every status change of a version, append-only (the database refuses updates and deletes).
 *
 * @property int $id
 * @property int $version_id
 * @property VersionStatus|null $from_status
 * @property VersionStatus $to_status
 * @property int|null $actor_id
 * @property string|null $reason
 * @property Carbon $occurred_at
 */
#[Fillable(['version_id', 'from_status', 'to_status', 'actor_id', 'reason', 'occurred_at'])]
final class VersionStatusLog extends Model
{
    protected $table = 'qb_version_status_log';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'from_status' => VersionStatus::class,
            'to_status' => VersionStatus::class,
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<QuestionVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(QuestionVersion::class, 'version_id');
    }
}
