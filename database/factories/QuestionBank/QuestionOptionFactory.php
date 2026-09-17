<?php

namespace Database\Factories\QuestionBank;

use App\Domain\QuestionBank\Models\QuestionOption;
use App\Domain\QuestionBank\Models\QuestionVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionOption>
 */
class QuestionOptionFactory extends Factory
{
    protected $model = QuestionOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version_id' => QuestionVersion::factory(),
            'label' => 'A',
            'body' => fake()->words(3, true),
            'is_correct' => false,
            'sort_order' => 1,
        ];
    }

    public function correct(): static
    {
        return $this->state(fn (): array => ['is_correct' => true]);
    }
}
