<?php

use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionAnswer;
use App\Domain\QuestionBank\Models\QuestionItem;
use App\Domain\QuestionBank\Models\QuestionOption;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\VersionStatusLog;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function version(VersionStatus $status = VersionStatus::Draft, array $attributes = []): QuestionVersion
{
    return QuestionVersion::factory()->create(['status' => $status] + $attributes);
}

test('the editor can offer every kind of question the blueprint lists', function () {
    $types = QuestionType::query()->orderBy('sort_order')->get()->keyBy('code');

    expect($types->keys()->all())->toEqualCanonicalizing([
        'single_best_answer', 'multiple_response', 'true_false', 'multiple_true_false',
        'extended_matching', 'matching', 'ordering', 'short_answer', 'numerical', 'cloze',
        'essay', 'image_labelling',
    ]);

    $sba = $types['single_best_answer'];
    expect($sba->has_options)->toBeTrue()
        ->and([$sba->options_min, $sba->options_max, $sba->correct_min, $sba->correct_max])->toBe([2, 10, 1, 1])
        ->and($sba->default_settings)->toHaveKey('shuffle_options');

    expect($types['multiple_response']->correct_max)->toBeNull()
        ->and($types['multiple_response']->supports_partial_credit)->toBeTrue()
        ->and($types['multiple_true_false']->item_answer->value)->toBe('boolean')
        ->and($types['extended_matching']->item_answer->value)->toBe('option')
        ->and($types['ordering']->item_answer->value)->toBe('position')
        ->and($types['cloze']->has_accepted_answers)->toBeTrue()
        ->and($types['numerical']->has_numeric_answer)->toBeTrue()
        ->and($types['essay']->is_manually_marked)->toBeTrue()
        ->and($types['essay']->supports_rubric)->toBeTrue()
        ->and($types['essay']->default_settings['max_words'])->toBe(500);
});

test('a draft version can be written to, including its options, sub-parts and answers', function () {
    $draft = version();

    $option = QuestionOption::factory()->create(['version_id' => $draft->id, 'label' => 'A', 'sort_order' => 1, 'is_correct' => true]);
    $item = QuestionItem::query()->create(['version_id' => $draft->id, 'sort_order' => 1, 'body' => 'Statement', 'is_true' => true]);
    $answer = QuestionAnswer::query()->create(['version_id' => $draft->id, 'answer_text' => 'aspirin', 'marks_fraction' => 1]);

    $draft->update(['stem' => '<p>Changed</p>', 'marks' => 2]);
    $option->update(['body' => 'Changed option']);
    $item->update(['body' => 'Changed statement']);
    $answer->update(['answer_text' => 'paracetamol']);

    expect($draft->fresh()->stem)->toBe('<p>Changed</p>')
        ->and($option->fresh()->body)->toBe('Changed option')
        ->and($item->fresh()->body)->toBe('Changed statement')
        ->and($answer->fresh()->answer_text)->toBe('paracetamol');

    $option->delete();
    $item->delete();
    expect($draft->options()->count())->toBe(0)->and($draft->items()->count())->toBe(0);
});

test('once a version leaves drafting, its content is frozen by the database', function () {
    $approved = version(VersionStatus::Approved);
    $stem = $approved->stem;

    expect(fn () => $approved->update(['stem' => '<p>Rewritten</p>']))->toThrow(QueryException::class, 'no longer a draft')
        ->and(fn () => $approved->update(['marks' => 5]))->toThrow(QueryException::class)
        ->and(fn () => $approved->update(['node_id' => 99]))->toThrow(QueryException::class)
        ->and(fn () => $approved->update(['content_hash' => str_repeat('a', 64)]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('qb_question_versions')->where('id', $approved->id)->update(['stem' => 'raw sql']))->toThrow(QueryException::class)
        ->and($approved->fresh()->stem)->toBe($stem);

    // Approval results and the status itself may still be recorded.
    $approved->refresh();
    $approved->update(['difficulty_level_id' => 1, 'cognitive_level_id' => 2, 'approved_at' => now(), 'approved_by' => $approved->author_id]);
    expect($approved->fresh()->difficulty_level_id)->toBe(1);
});

test('options, sub-parts, answers, references, media links and tags of a frozen version cannot change', function () {
    $draft = version();
    $option = QuestionOption::factory()->create(['version_id' => $draft->id]);
    $item = QuestionItem::query()->create(['version_id' => $draft->id, 'sort_order' => 1, 'body' => 'Statement']);
    $draft->update(['status' => VersionStatus::Submitted]);

    expect(fn () => QuestionOption::factory()->create(['version_id' => $draft->id, 'label' => 'Z', 'sort_order' => 9]))->toThrow(QueryException::class, 'no longer a draft')
        ->and(fn () => $option->update(['body' => 'Changed']))->toThrow(QueryException::class)
        ->and(fn () => $option->delete())->toThrow(QueryException::class)
        ->and(fn () => $item->delete())->toThrow(QueryException::class)
        ->and(fn () => QuestionAnswer::query()->create(['version_id' => $draft->id, 'answer_text' => 'x']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('qb_references')->insert(['version_id' => $draft->id, 'kind' => 'book', 'citation' => 'Harrison', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('qb_version_tags')->insert(['version_id' => $draft->id, 'tag_id' => 1]))->toThrow(QueryException::class)
        ->and($draft->options()->count())->toBe(1);
});

test('a version whose changes were requested can be edited again', function () {
    $version = version(VersionStatus::UnderReview);
    $version->update(['status' => VersionStatus::ChangesRequested]);

    $version->update(['stem' => '<p>Improved</p>']);
    QuestionOption::factory()->create(['version_id' => $version->id, 'label' => 'B', 'sort_order' => 2]);

    expect($version->fresh()->stem)->toBe('<p>Improved</p>')
        ->and($version->options()->count())->toBe(1);
});

test('the database allows only the workflow steps of the blueprint', function () {
    $version = version();

    expect(fn () => $version->update(['status' => VersionStatus::Approved]))->toThrow(QueryException::class, 'not an allowed status change')
        ->and(fn () => $version->update(['status' => VersionStatus::Active]))->toThrow(QueryException::class)
        ->and($version->fresh()->status)->toBe(VersionStatus::Draft);

    foreach ([VersionStatus::Submitted, VersionStatus::UnderReview, VersionStatus::Approved, VersionStatus::Active, VersionStatus::OnHold, VersionStatus::Active, VersionStatus::Retired] as $next) {
        $version->update(['status' => $next]);
        expect($version->fresh()->status)->toBe($next);
    }

    expect(fn () => $version->update(['status' => VersionStatus::Active]))->toThrow(QueryException::class)
        ->and($version->fresh()->status)->toBe(VersionStatus::Retired);
});

test('the status enum in code and the database agree', function () {
    foreach (VersionStatus::cases() as $status) {
        foreach (VersionStatus::cases() as $next) {
            if ($status === $next) {
                continue;
            }
            $version = version($status);
            $result = rescue(fn () => (bool) $version->update(['status' => $next]), false, false);

            expect($result)->toBe(
                $status->canMoveTo($next),
                "{$status->value} → {$next->value}: the database and VersionStatus::canMoveTo disagree",
            );
        }
    }
});

test('only one version of a question can be active', function () {
    $question = Question::factory()->create();
    $first = version(VersionStatus::Approved, ['question_id' => $question->id, 'version_no' => 1]);
    $second = version(VersionStatus::Approved, ['question_id' => $question->id, 'version_no' => 2]);

    $first->update(['status' => VersionStatus::Active]);

    expect(fn () => $second->update(['status' => VersionStatus::Active]))->toThrow(UniqueConstraintViolationException::class);

    // The usual move: the old version is superseded in the same transaction as the new one going live.
    DB::transaction(function () use ($first, $second): void {
        $first->update(['status' => VersionStatus::Superseded]);
        $second->update(['status' => VersionStatus::Active]);
    });

    expect($first->fresh()->status)->toBe(VersionStatus::Superseded)
        ->and($second->fresh()->status)->toBe(VersionStatus::Active)
        ->and(QuestionVersion::query()->where('question_id', $question->id)->where('status', VersionStatus::Active)->count())->toBe(1);
});

test('questions are archived, never deleted, and only a draft version can be deleted', function () {
    $question = Question::factory()->create();
    $draft = version(VersionStatus::Draft, ['question_id' => $question->id]);
    QuestionOption::factory()->create(['version_id' => $draft->id]);
    $submitted = version(VersionStatus::Submitted, ['question_id' => $question->id, 'version_no' => 2]);

    expect(fn () => $question->delete())->toThrow(QueryException::class, 'archived, never deleted')
        ->and(fn () => $submitted->delete())->toThrow(QueryException::class, 'Only a draft version can be deleted');

    $draft->delete();
    expect(QuestionVersion::query()->find($draft->id))->toBeNull()
        ->and(DB::table('qb_question_options')->where('version_id', $draft->id)->count())->toBe(0);

    $question->update(['is_archived' => true, 'archived_at' => now(), 'archive_reason' => 'Out of curriculum']);
    expect($question->fresh()->is_archived)->toBeTrue();
});

test('the status log is append-only', function () {
    $version = version();
    $entry = VersionStatusLog::query()->create([
        'version_id' => $version->id, 'from_status' => VersionStatus::Draft, 'to_status' => VersionStatus::Submitted,
        'actor_id' => $version->author_id, 'occurred_at' => now(),
    ]);

    expect(fn () => $entry->update(['to_status' => VersionStatus::Approved]))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => $entry->delete())->toThrow(QueryException::class, 'append-only')
        ->and($version->statusLog()->count())->toBe(1);
});

test('option labels and positions are unique within a version, and per sub-part', function () {
    $draft = version();
    QuestionOption::factory()->create(['version_id' => $draft->id, 'label' => 'A', 'sort_order' => 1]);

    expect(fn () => QuestionOption::factory()->create(['version_id' => $draft->id, 'label' => 'A', 'sort_order' => 2]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => QuestionOption::factory()->create(['version_id' => $draft->id, 'label' => 'B', 'sort_order' => 1]))->toThrow(UniqueConstraintViolationException::class);

    $item = QuestionItem::query()->create(['version_id' => $draft->id, 'sort_order' => 1, 'body' => 'Blank 1']);
    QuestionOption::factory()->create(['version_id' => $draft->id, 'item_id' => $item->id, 'label' => 'A', 'sort_order' => 1]);

    expect($draft->options()->count())->toBe(2);
});

test('a question keeps its campus, course and reference, and references are unique', function () {
    $question = Question::factory()->create(['branch_id' => 3, 'course_id' => 42, 'public_ref' => 'Q-2026-000001']);

    expect($question->fresh()->branch_id)->toBe(3)
        ->and(fn () => Question::factory()->create(['public_ref' => 'Q-2026-000001']))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('qb_questions')->insert(['public_ref' => 'Q-2026-000002', 'course_id' => 1, 'created_by' => $question->created_by]))->toThrow(QueryException::class);
});

test('question text is indexed for searching', function () {
    // MySQL only adds rows to a FULLTEXT index on commit, so searching itself is covered by the
    // search step; here the index has to exist.
    $index = DB::selectOne("SHOW INDEX FROM qb_question_versions WHERE Key_name = 'ft_qb_versions_search'"); // raw-sql-reviewed: fixed statement, no input

    expect($index)->not->toBeNull()
        ->and($index->Index_type)->toBe('FULLTEXT')
        ->and($index->Column_name)->toBe('search_text');
});
