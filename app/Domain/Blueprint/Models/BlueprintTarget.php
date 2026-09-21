<?php

namespace App\Domain\Blueprint\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The share of the paper that should be at one cognitive or difficulty level.
 *
 * @property int $id
 * @property int $blueprint_id
 * @property string $dimension cognitive|difficulty
 * @property int $level_id
 * @property float $percent
 */
#[Fillable(['blueprint_id', 'dimension', 'level_id', 'percent'])]
final class BlueprintTarget extends Model
{
    protected $table = 'exm_blueprint_targets';

    protected function casts(): array
    {
        return ['percent' => 'float'];
    }
}
