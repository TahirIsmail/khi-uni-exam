<?php

namespace Database\Factories\QuestionBank;

use App\Domain\QuestionBank\Models\Question;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_ref' => 'Q-'.now()->year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'branch_id' => 1,
            'course_id' => 1,
            'latest_version_no' => 0,
            'created_by' => User::factory(),
        ];
    }
}
