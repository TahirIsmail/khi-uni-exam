<?php

use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\ImportRow;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionImport;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\Tag;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\InteractsWithCms;
use Tests\Concerns\PassesMfa;

uses(InteractsWithCms::class, PassesMfa::class);

beforeEach(function () {
    Storage::fake('local');
    $this->shareCmsConnection();
    $this->branch = $this->cmsBranch('Main Campus');
    $this->programme = $this->cmsProgramme($this->branch, 'MBBS');
    $this->professional = $this->cmsProfessional($this->programme);
    $this->course = $this->cmsCourse($this->programme, $this->professional, 'CVS');
    $this->courseCode = DB::table(config('database.cms_source_database').'.acad_courses')->where('id', $this->course)->value('course_code');
    $this->node = $this->cmsCurriculumNode($this->course, $this->programme, 'Acute coronary syndrome');
    $this->cmsExamType('annual');

    $role = $this->cmsRole('Faculty');
    $this->cmsGrant($role, 'qbank_questions', 'view', 'add');
    $this->cmsGrant($role, 'qbank_import', 'view', 'add');
    $this->importer = $this->staffUser([$role], $this->branch);
});

/**
 * A CSV file exactly as an author would export it from Excel. Every question is filed under an
 * examination and judged for its cognitive and difficulty level, so those last three columns are
 * filled in unless a row gives them.
 */
function csv(array $rows, array $header = ['type', 'course', 'topic', 'stem', 'lead_in', 'marks', 'options', 'correct', 'answers', 'items', 'references', 'tags', 'exam_type', 'cognitive', 'difficulty']): UploadedFile
{
    $filing = ['exam_type' => 'Annual', 'cognitive' => 'Application', 'difficulty' => 'Moderate'];

    $lines = [implode(',', $header)];
    foreach ($rows as $row) {
        $row = array_pad($row, count($header), '');
        foreach ($filing as $column => $value) {
            $index = array_search($column, $header, true);
            if ($index !== false && $row[$index] === '') {
                $row[$index] = $value;
            }
        }
        $lines[] = implode(',', array_map(fn (string $cell): string => '"'.str_replace('"', '""', $cell).'"', $row));
    }

    return UploadedFile::fake()->createWithContent('questions.csv', implode("\n", $lines));
}

function sbaRow(array $overrides = []): array
{
    return array_replace([
        'sba', test()->courseCode, 'Acute coronary syndrome',
        'A 54-year-old man has crushing chest pain radiating to the jaw for 40 minutes.',
        'Which investigation is most useful first?', '1',
        'ECG | Chest radiograph | Echocardiogram | Coronary angiography', 'A', '', '',
        'Harrison, 21st ed p. 1875', 'ECG, cardiology',
    ], $overrides);
}

test('a spreadsheet is read and checked without touching the bank', function () {
    $response = $this->actingAs($this->importer)->post('/questions/imports', ['file' => csv([sbaRow()])]);

    $import = QuestionImport::query()->firstOrFail();
    $response->assertRedirect("/questions/imports/{$import->id}");

    expect($import->rows_total)->toBe(1)
        ->and($import->rows_valid)->toBe(1)
        ->and($import->rows_invalid)->toBe(0)
        ->and($import->status)->toBe('checked')
        ->and($import->branch_id)->toBe($this->branch)
        ->and(Question::query()->count())->toBe(0);

    $row = ImportRow::query()->firstOrFail();
    expect($row->row_number)->toBe(2)
        ->and($row->status)->toBe('valid')
        ->and($row->parsed['type'])->toBe('Single best answer (MCQ)')
        ->and($row->parsed['options'])->toBe(4)
        ->and($row->parsed['correct'])->toBe(1)
        ->and($row->parsed['topic'])->toBe('Acute coronary syndrome')
        ->and($row->errors)->toBeNull();

    expect(DB::table('sec_audit_logs')->where('action', 'qbank.import.checked')->exists())->toBeTrue();
});

test('committing creates drafts that remember the line they came from', function () {
    $this->actingAs($this->importer)->post('/questions/imports', ['file' => csv([sbaRow(), sbaRow([3 => 'A child with a barking cough and stridor at night.'])])]);
    $import = QuestionImport::query()->firstOrFail();

    $this->actingAs($this->importer)->post("/questions/imports/{$import->id}/commit")->assertRedirect("/questions/imports/{$import->id}");

    $import->refresh();
    expect($import->status)->toBe('committed')
        ->and($import->rows_committed)->toBe(2)
        ->and(Question::query()->count())->toBe(2);

    $version = QuestionVersion::query()->latest('id')->firstOrFail();
    $row = ImportRow::query()->where('version_id', $version->id)->firstOrFail();

    expect($version->status)->toBe(VersionStatus::Draft)
        ->and($version->source)->toBe('import')
        ->and($version->import_row_id)->toBe($row->id)
        ->and($version->author_id)->toBe($this->importer->id)
        ->and($version->options()->count())->toBe(4)
        ->and($version->references()->count())->toBe(1)
        ->and($row->status)->toBe('committed')
        ->and($row->question_id)->toBe($version->question_id);

    expect(DB::table('sec_audit_logs')->where('action', 'qbank.import.committed')->exists())->toBeTrue();
    $this->actingAs($this->importer)->post("/questions/imports/{$import->id}/commit")->assertSessionHasErrors('import');
});

test('every kind of question in the file is understood', function () {
    $rows = [
        sbaRow(),
        ['mtf', $this->courseCode, 'Acute coronary syndrome', 'Regarding an inferior myocardial infarction:', '', '3', '', '', '',
            'Aspirin reduces mortality = true | Nitrates are safe in right ventricular infarction = false | Reperfusion within 90 minutes is the aim = true', '', ''],
        ['emq', $this->courseCode, 'Acute coronary syndrome', 'Match each presentation with the most likely diagnosis.', '', '4',
            'A) Myocardial infarction | B) Pericarditis | C) Aortic dissection | D) Pulmonary embolism', '', '',
            'Tearing pain radiating to the back -> C | Pain relieved by sitting forward -> B', '', ''],
        ['short_answer', $this->courseCode, 'Acute coronary syndrome', 'Which enzyme confirms myocardial injury?', '', '1', '', '', 'troponin | troponin I', '', '', ''],
        ['numerical', $this->courseCode, 'Acute coronary syndrome', 'What is the normal arterial pH?', '', '1', '', '', '7.40 ± 0.05', '', '', ''],
        ['true_false', $this->courseCode, 'Acute coronary syndrome', 'Aspirin is given in acute coronary syndrome.', '', '1', '', 'true', '', '', '', ''],
        ['essay', $this->courseCode, 'Acute coronary syndrome', 'Describe the management of an ST elevation myocardial infarction in the first hour.', '', '10', '', '', '', '', '', ''],
    ];

    $this->actingAs($this->importer)->post('/questions/imports', ['file' => csv($rows)]);
    $import = QuestionImport::query()->firstOrFail();

    expect($import->rows_valid)->toBe(7)
        ->and($import->rows_invalid)->toBe(0);

    $this->actingAs($this->importer)->post("/questions/imports/{$import->id}/commit");

    $byType = QuestionVersion::query()->with('type')->get()->keyBy(fn (QuestionVersion $version): string => $version->type->code);

    expect($byType->keys()->sort()->values()->all())->toBe([
        'essay', 'extended_matching', 'multiple_true_false', 'numerical', 'short_answer', 'single_best_answer', 'true_false',
    ])
        ->and($byType['multiple_true_false']->items()->count())->toBe(3)
        ->and($byType['multiple_true_false']->items()->where('is_true', true)->count())->toBe(2)
        ->and($byType['extended_matching']->items()->count())->toBe(2)
        ->and($byType['extended_matching']->items()->orderBy('sort_order')->first()->correctOption->label)->toBe('C')
        ->and($byType['short_answer']->answers()->count())->toBe(2)
        ->and($byType['numerical']->answers()->first()->numeric_value)->toBe(7.4)
        ->and($byType['numerical']->answers()->first()->tolerance)->toBe(0.05)
        ->and($byType['true_false']->options()->where('is_correct', true)->value('body'))->toContain('True')
        ->and($byType['essay']->marks)->toBe(10.0);
});

test('an Excel file works as well as a CSV', function () {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['Type of question', 'Examination', 'Course ID', 'Topic', 'Question', 'Lead-in', 'Marks', 'Options', 'Answer key', 'Bloom', 'Difficulty level'],
        ['SBA', 'Annual', $this->courseCode, 'Acute coronary syndrome', 'A 54-year-old man has crushing chest pain radiating to the jaw.', 'Which is first?', 1, 'ECG | Chest radiograph', 'A', 'Application', 'Moderate'],
    ]);
    $path = storage_path('app/private/test-import.xlsx');
    @mkdir(dirname($path), 0777, true);
    (new Xlsx($spreadsheet))->save($path);

    $this->actingAs($this->importer)->post('/questions/imports', [
        'file' => new UploadedFile($path, 'questions.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
    ]);

    $import = QuestionImport::query()->firstOrFail();

    expect($import->format)->toBe('xlsx')
        ->and($import->rows_total)->toBe(1)
        ->and($import->rows_valid)->toBe(1);

    @unlink($path);
});

test('rows with a problem are reported with their line number and keep the file usable', function () {
    $rows = [
        sbaRow(),
        sbaRow([0 => 'nonsense-type']),
        sbaRow([1 => 'NO-SUCH-COURSE']),
        sbaRow([2 => 'Topic that does not exist']),
        sbaRow([7 => 'Z']),
        sbaRow([3 => 'Short']),
        ['mtf', $this->courseCode, 'Acute coronary syndrome', 'Regarding this condition:', '', '1', '', '', '', 'A statement with no answer | Another one = true', '', ''],
    ];

    $this->actingAs($this->importer)->post('/questions/imports', ['file' => csv($rows)]);
    $import = QuestionImport::query()->firstOrFail();

    expect($import->rows_total)->toBe(7)
        ->and($import->rows_valid)->toBe(1)
        ->and($import->rows_invalid)->toBe(6);

    $errorsByLine = ImportRow::query()->get()->mapWithKeys(fn (ImportRow $row): array => [
        $row->row_number => collect($row->errors ?? [])->flatten()->implode(' '),
    ]);

    expect($errorsByLine[3])->toContain('no question type called "nonsense-type"')
        ->and($errorsByLine[4])->toContain('no active course "NO-SUCH-COURSE"')
        ->and($errorsByLine[5])->toContain('no topic "Topic that does not exist"')
        ->and($errorsByLine[6])->toContain('not one of the options')
        ->and($errorsByLine[7])->toContain('at least 10 characters')
        ->and($errorsByLine[8])->toContain('true or false');

    // Committing takes the good row and leaves the rest for a corrected file.
    $this->actingAs($this->importer)->post("/questions/imports/{$import->id}/commit");
    expect(Question::query()->count())->toBe(1)
        ->and($import->fresh()->rows_committed)->toBe(1);
});

test('the same question twice in one file is refused, and one already in the bank is only a warning', function () {
    $this->actingAs($this->importer)->post('/questions/imports', ['file' => csv([sbaRow(), sbaRow()])]);
    $first = QuestionImport::query()->firstOrFail();

    expect($first->rows_valid)->toBe(1)
        ->and($first->rows_invalid)->toBe(1)
        ->and(ImportRow::query()->where('row_number', 3)->value('status'))->toBe('duplicate')
        ->and(collect(ImportRow::query()->where('row_number', 3)->first()->errors)->flatten()->implode(' '))->toContain('line 2 of this file');

    $this->actingAs($this->importer)->post("/questions/imports/{$first->id}/commit");

    $this->actingAs($this->importer)->post('/questions/imports', ['file' => csv([sbaRow()])]);
    $second = QuestionImport::query()->latest('id')->firstOrFail();
    $row = $second->rows()->firstOrFail();

    expect($second->rows_valid)->toBe(1)
        ->and($row->status)->toBe('valid')
        ->and(implode(' ', $row->warnings ?? []))->toContain('already in the bank');
});

test('defaults chosen for the file fill in what the rows leave out', function () {
    $file = csv([['sba', '', '', 'A 54-year-old man has crushing chest pain radiating to the jaw.', '', '1', 'ECG | Chest radiograph', 'A', '', '', '', '']]);

    $this->actingAs($this->importer)->post('/questions/imports', [
        'file' => $file,
        'course_id' => $this->course,
        'node_id' => $this->node,
        'type_id' => (int) QuestionType::query()->where('code', 'single_best_answer')->value('id'),
    ]);

    $import = QuestionImport::query()->firstOrFail();
    expect($import->rows_valid)->toBe(1)
        ->and($import->defaults['course_id'])->toBe($this->course);

    $this->actingAs($this->importer)->post("/questions/imports/{$import->id}/commit");
    expect(QuestionVersion::query()->firstOrFail()->node_id)->toBe($this->node);
});

test('a row for a course outside the exam access is refused', function () {
    $otherProgramme = $this->cmsProgramme($this->branch, 'BDS');
    $otherCourse = $this->cmsCourse($otherProgramme, $this->cmsProfessional($otherProgramme), 'ORAL');
    $otherCode = DB::table(config('database.cms_source_database').'.acad_courses')->where('id', $otherCourse)->value('course_code');
    $otherNode = $this->cmsCurriculumNode($otherCourse, $otherProgramme, 'Oral anatomy');
    $this->cmsExamScope($this->importer, 'course', $this->course);

    $this->actingAs($this->importer)->post('/questions/imports', [
        'file' => csv([sbaRow(), sbaRow([1 => $otherCode, 2 => 'Oral anatomy'])]),
    ]);

    $import = QuestionImport::query()->firstOrFail();

    expect($import->rows_valid)->toBe(1)
        ->and(collect(ImportRow::query()->where('row_number', 3)->first()->errors)->flatten()->implode(' '))->toContain('exam access');

    expect($otherNode)->toBeInt();
});

test('tags named in the file are used when the campus already has them', function () {
    $tag = $this->actingAs($this->importer)->postJson('/questions/tags', ['name' => 'ECG'])->json();

    $this->actingAs($this->importer)->post('/questions/imports', ['file' => csv([sbaRow()])]);
    $import = QuestionImport::query()->firstOrFail();
    $this->actingAs($this->importer)->post("/questions/imports/{$import->id}/commit");

    expect(QuestionVersion::query()->firstOrFail()->tags()->pluck('qb_tags.id')->all())->toBe([$tag['id']])
        ->and(Tag::query()->count())->toBe(1);
});

test('a file that is not a spreadsheet, or has no question column, is refused', function () {
    $this->actingAs($this->importer)->from('/questions/imports')
        ->post('/questions/imports', ['file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('file');

    $this->actingAs($this->importer)->from('/questions/imports')
        ->post('/questions/imports', ['file' => UploadedFile::fake()->createWithContent('questions.csv', "name,age\nAyesha,20")])
        ->assertSessionHasErrors('file');

    $this->actingAs($this->importer)->from('/questions/imports')
        ->post('/questions/imports', ['file' => UploadedFile::fake()->createWithContent('questions.csv', '')])
        ->assertSessionHasErrors('file');

    expect(QuestionImport::query()->count())->toBe(0);
});

test('checking and committing are separate permissions, and imports stay in their campus', function () {
    $checkOnlyRole = $this->cmsRole('Question writer');
    $this->cmsGrant($checkOnlyRole, 'qbank_questions', 'view', 'add');
    $this->cmsGrant($checkOnlyRole, 'qbank_import', 'view');
    $checker = $this->staffUser([$checkOnlyRole], $this->branch);

    $this->actingAs($checker)->post('/questions/imports', ['file' => csv([sbaRow()])]);
    $import = QuestionImport::query()->firstOrFail();

    // The file was checked, but this user may not put anything into the bank.
    $this->actingAs($checker)->post("/questions/imports/{$import->id}/commit")->assertForbidden();
    expect(Question::query()->count())->toBe(0);

    // Another campus does not see the file at all.
    DB::table('qb_imports')->where('id', $import->id)->update(['branch_id' => $this->cmsBranch('City Campus')]);
    $this->actingAs($this->importer)->get("/questions/imports/{$import->id}")->assertNotFound();
    $this->actingAs($this->importer)->post("/questions/imports/{$import->id}/commit")->assertNotFound();

    $outsider = $this->staffUser([$this->cmsRole('Receptionist')], $this->branch);
    $this->actingAs($outsider)->get('/questions/imports')->assertForbidden();
    $this->actingAs($outsider)->post('/questions/imports', ['file' => csv([sbaRow()])])->assertForbidden();
});

test('a checked file can be discarded, a committed one cannot', function () {
    $this->actingAs($this->importer)->post('/questions/imports', ['file' => csv([sbaRow()])]);
    $import = QuestionImport::query()->firstOrFail();

    $this->actingAs($this->importer)->delete("/questions/imports/{$import->id}")->assertRedirect('/questions/imports');
    expect($import->fresh()->status)->toBe('discarded');
    Storage::disk('local')->assertMissing($import->path);

    $this->actingAs($this->importer)->post("/questions/imports/{$import->id}/commit")->assertSessionHasErrors('import');
    expect(Question::query()->count())->toBe(0);

    // A committed file is kept as the record of what was imported.
    $this->actingAs($this->importer)->post('/questions/imports', ['file' => csv([sbaRow()])]);
    $committed = QuestionImport::query()->latest('id')->firstOrFail();
    $this->actingAs($this->importer)->post("/questions/imports/{$committed->id}/commit");
    $this->actingAs($this->importer)->delete("/questions/imports/{$committed->id}")->assertStatus(422);
    expect($committed->fresh()->status)->toBe('committed');
});

test('the template is KMU\'s own format, and the full one shows an example of each common type', function () {
    $csv = $this->actingAs($this->importer)->get('/questions/imports/template')->assertOk()->streamedContent();
    $lines = array_map(str_getcsv(...), array_filter(explode("\n", trim($csv))));

    expect($lines[0])->toBe(['Question No', 'exam_type', 'Academic Year', 'subject', 'discipline', 'stem', 'lead_in', 'options', 'correct option'])
        ->and(array_column(array_slice($lines, 1), 7))->toBe(['Axillary nerve', 'Radial nerve', 'Musculocutaneous nerve', 'Suprascapular nerve'])
        ->and($lines[1][8])->toBe('A');

    $full = $this->actingAs($this->importer)->get('/questions/imports/template?full=1')->assertOk()->streamedContent();
    $lines = array_map(str_getcsv(...), array_filter(explode("\n", trim($full))));
    expect($lines[0])->toContain('stem')->toContain('options')->toContain('correct')
        ->and(array_column(array_slice($lines, 1), 0))->toBe(['sba', 'mtf', 'short_answer', 'numerical']);
});

/** A file in KMU's format, as KMU sent it: each option on a line of its own under its question. */
function kmuCsv(string $program, string $subject = 'Anatomy', string $year = '2026'): UploadedFile
{
    $lines = [
        'Question No,exam_type,Academic Year,subject,discipline,stem,lead_in,options,correct option',
        "1,annual,{$year},{$subject},{$program},\"After a fall on an outstretched hand, a 25-year-old man cannot abduct his arm beyond 15 degrees, and the skin over the lower deltoid is numb.\",Which nerve is most likely injured?,Axillary nerve ,A",
        ',,,,,,, Radial nerve,',
        ',,,,,,,Musculocutaneous nerve ,',
        ',,,,,,,Suprascapular nerve,',
        "2,supplementary,{$year},{$subject},{$program},Which muscle is the main flexor of the elbow joint?,,Biceps brachii,B",
        ',,,,,,,Brachialis,',
        ',,,,,,,Brachioradialis,',
    ];

    return UploadedFile::fake()->createWithContent('kmu-question-import-template.csv', implode("\n", $lines));
}

test('KMU\'s format imports as it is: options one per line, the module chosen on the screen', function () {
    $cms = config('database.cms_source_database');
    DB::table("{$cms}.acad_programme_profiles")->where('class_id', $this->programme)->update(['structure_type' => 'modular']);
    $program = DB::table("{$cms}.acad_programme_profiles")->where('class_id', $this->programme)->value('code');
    $anatomy = $this->cmsCurriculumNode($this->course, $this->programme, 'Anatomy');
    $intake = (int) DB::table("{$cms}.sessions")->insertGetId(['branch_id' => $this->branch, 'session' => '2026 Intake', 'start_date' => '2026-09-01', 'is_active' => 'no']);
    $this->cmsExamType('supplementary');

    $this->actingAs($this->importer)->post('/questions/imports', ['file' => kmuCsv($program), 'course_id' => $this->course])->assertRedirect();

    $import = QuestionImport::query()->firstOrFail();
    $rows = $import->rows()->orderBy('row_number')->get();
    expect($import->rows_total)->toBe(2)
        ->and($rows->pluck('status')->all())->toBe(['valid', 'valid'])
        ->and($rows[0]->parsed['options'])->toBe(4)
        ->and($rows[0]->parsed['correct'])->toBe(1)
        ->and($rows[0]->parsed['node_id'])->toBe($anatomy)
        ->and($rows[1]->parsed['options'])->toBe(3);

    $this->actingAs($this->importer)->post("/questions/imports/{$import->id}/commit")->assertRedirect();

    $first = QuestionVersion::query()->where('import_row_id', $rows[0]->id)->firstOrFail();
    expect($first->node_id)->toBe($anatomy)
        ->and($first->intake_id)->toBe($intake)
        ->and($first->exam_type_id)->toBe($this->cmsExamType('annual'))
        ->and($first->options()->orderBy('sort_order')->pluck('body')->map(fn ($body) => strip_tags($body))->all())
        ->toBe(['Axillary nerve', 'Radial nerve', 'Musculocutaneous nerve', 'Suprascapular nerve'])
        ->and($first->options()->where('is_correct', true)->value('label'))->toBe('A');
    expect(QuestionVersion::query()->where('import_row_id', $rows[1]->id)->value('exam_type_id'))->toBe($this->cmsExamType('supplementary'));
});

test('in KMU\'s format, a BDS subject that is not a topic files the question on the course itself', function () {
    $program = DB::table(config('database.cms_source_database').'.acad_programme_profiles')->where('class_id', $this->programme)->value('code');
    DB::table(config('database.cms_source_database').'.sessions')->insert(['branch_id' => $this->branch, 'session' => '2026 Intake', 'start_date' => '2026-09-01', 'is_active' => 'no']);

    $this->actingAs($this->importer)->post('/questions/imports', ['file' => kmuCsv($program, 'Dental Anatomy'), 'course_id' => $this->course]);

    $row = QuestionImport::query()->firstOrFail()->rows()->orderBy('row_number')->firstOrFail();
    expect($row->status)->toBe('valid')
        ->and($row->parsed['node_id'])->toBeNull()
        ->and($row->parsed['topic'])->toBe('The whole course')
        ->and($row->warnings[0])->toContain('filed on the course as a whole');
});

test('in KMU\'s format, the module must be chosen, the program must match it, and MBBS needs a known subject', function () {
    $cms = config('database.cms_source_database');
    $program = DB::table("{$cms}.acad_programme_profiles")->where('class_id', $this->programme)->value('code');

    // No module or course chosen: the file does not name one.
    $this->actingAs($this->importer)->post('/questions/imports', ['file' => kmuCsv($program)]);
    expect(collect(QuestionImport::query()->latest('id')->first()->rows()->first()->errors)->flatten()->first())
        ->toBe('Choose the module or course on the import screen (the file does not name one).');

    // A line for another program than the chosen module's.
    $other = $this->cmsProgramme($this->branch, 'BDS');
    $otherCode = DB::table("{$cms}.acad_programme_profiles")->where('class_id', $other)->value('code');
    $this->actingAs($this->importer)->post('/questions/imports', ['file' => kmuCsv($otherCode), 'course_id' => $this->course]);
    expect(collect(QuestionImport::query()->latest('id')->first()->rows()->first()->errors['program'] ?? [])->first())
        ->toContain('but the module or course chosen belongs to another program');

    // MBBS: the subject must exist under the module.
    DB::table("{$cms}.acad_programme_profiles")->where('class_id', $this->programme)->update(['structure_type' => 'modular']);
    $this->actingAs($this->importer)->post('/questions/imports', ['file' => kmuCsv($program, 'Embryology'), 'course_id' => $this->course]);
    expect(collect(QuestionImport::query()->latest('id')->first()->rows()->first()->errors['subject'] ?? [])->first())
        ->toContain('has no subject "Embryology"');

    // An Academic Year the campus has no session for.
    $this->actingAs($this->importer)->post('/questions/imports', ['file' => kmuCsv($program, 'Acute coronary syndrome', '2031'), 'course_id' => $this->course]);
    expect(collect(QuestionImport::query()->latest('id')->first()->rows()->first()->errors['session'] ?? [])->first())
        ->toBe('There is no Academic Session "2031" in this campus.');
});

test('the preview shows the rows, and can show only the ones with a problem', function () {
    $this->actingAs($this->importer)->post('/questions/imports', ['file' => csv([sbaRow(), sbaRow([0 => 'nonsense'])])]);
    $import = QuestionImport::query()->firstOrFail();

    $this->actingAs($this->importer)->get("/questions/imports/{$import->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('qbank/ImportPreview')
        ->where('import.rowsTotal', 2)
        ->where('import.rowsValid', 1)
        ->where('import.committable', true)
        ->where('rows.total', 2)
        ->where('canCommit', true));

    $this->actingAs($this->importer)->get("/questions/imports/{$import->id}?only=invalid")
        ->assertInertia(fn ($page) => $page->where('rows.total', 1)
            ->where('rows.data.0.rowNumber', 3)
            ->where('rows.data.0.errors.0', fn ($message) => str_contains((string) $message, 'nonsense')));

    $this->actingAs($this->importer)->get('/questions/imports')->assertInertia(fn ($page) => $page
        ->component('qbank/Imports')
        ->where('imports.data.0.rowsInvalid', 1)
        ->where('columns', fn ($columns) => in_array('stem', $columns->all(), true)));
});

test('the examination is read from the file, or taken from the default, and must suit the programme', function () {
    $this->cmsExamType('supplementary');
    $regular = $this->cmsExamType('regular');

    $this->actingAs($this->importer)->post('/questions/imports', [
        'file' => csv([
            sbaRow([12 => 'Supplementary']),
            sbaRow([3 => 'A second question with a different stem about chest pain.', 12 => 'Retake exam of some kind']),
            sbaRow([3 => 'A third question with a different stem about chest pain.', 12 => 'Regular']),
        ]),
    ]);
    $import = QuestionImport::query()->firstOrFail();
    $rows = ImportRow::query()->where('import_id', $import->id)->orderBy('row_number')->get();

    expect($rows[0]->status)->toBe('valid')
        ->and(collect($rows[1]->errors)->flatten()->implode(' '))->toContain('no examination type')
        ->and(collect($rows[2]->errors)->flatten()->implode(' '))->toContain('not used by this programme');

    // A file with no examination column takes the one chosen for the file.
    $this->actingAs($this->importer)->post('/questions/imports', [
        'file' => csv([sbaRow([3 => 'A fourth question, filed by the default examination.'])], ['type', 'course', 'topic', 'stem', 'lead_in', 'marks', 'options', 'correct', 'answers', 'items', 'references', 'tags', 'cognitive', 'difficulty']),
        'exam_type_id' => $this->cmsExamType('supplementary'),
    ]);
    $second = QuestionImport::query()->latest('id')->firstOrFail();
    $this->actingAs($this->importer)->post("/questions/imports/{$second->id}/commit");

    expect(QuestionVersion::query()->latest('id')->value('exam_type_id'))->toBe($this->cmsExamType('supplementary'))
        ->and($regular)->toBeInt();
});
