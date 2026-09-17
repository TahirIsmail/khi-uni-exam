<?php

namespace Database\Factories\QuestionBank;

use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionVersion>
 */
class QuestionVersionFactory extends Factory
{
    protected $model = QuestionVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $stem = fake()->sentence(12);

        return [
            'question_id' => Question::factory(),
            'version_no' => 1,
            'question_type_id' => fn (): int => QuestionType::query()->where('code', 'single_best_answer')->value('id'),
            'branch_id' => 1,
            'stem' => '<p>'.$stem.'</p>',
            'lead_in' => 'Which of the following is the most likely diagnosis?',
            'settings' => ['shuffle_options' => true],
            'marks' => 1,
            'negative_marks' => 0,
            'course_id' => 1,
            'node_id' => 1,
            'status' => VersionStatus::Draft,
            'content_hash' => hash('sha256', $stem),
            'search_text' => $stem,
            'author_id' => User::factory(),
            // The same person, resolved after author_id above.
            'created_by' => fn (array $attributes): int => (int) $attributes['author_id'],
        ];
    }

    public function status(VersionStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
