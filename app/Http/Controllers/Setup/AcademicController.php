<?php

namespace App\Http\Controllers\Setup;

use App\Domain\Identity\ActiveBranch;
use App\Http\Controllers\Controller;
use App\Support\Admin\AcademicStructure;
use App\Support\Admin\AdminTables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * Setup → Programmes & courses, and the short lists (intakes, exam types, disciplines): what kmu-cms
 * keeps under Academics. Programmes, courses and intakes belong to the campus being worked in.
 */
final class AcademicController extends Controller
{
    private const COURSE_CODE = '/^[A-Za-z0-9][A-Za-z0-9-]{2,19}$/';

    public function __construct(
        private readonly AcademicStructure $academic,
        private readonly ActiveBranch $activeBranch,
    ) {}

    public function programmes(Request $request): Response
    {
        return Inertia::render('setup/Programmes', ['programmes' => $this->academic->programmes($this->branchId($request))]);
    }

    public function storeProgramme(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'code' => ['required', 'string', 'max:20', 'unique:'.AdminTables::rule('acad_programme_profiles').',code'],
            'calendar' => ['required', 'in:annual,semester'],
            'structure' => ['required', 'in:modular,subject'],
            'years' => ['required', 'integer', 'min:1', 'max:10'],
        ]);
        $this->academic->createProgramme($this->branchId($request), $data);

        return $this->done('Programme added. Now add its courses.');
    }

    public function updateProgramme(Request $request, int $programme): RedirectResponse
    {
        $this->programmeOrFail($request, $programme);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'code' => ['required', 'string', 'max:20', Rule::unique(AdminTables::rule('acad_programme_profiles'), 'code')->ignore($programme, 'class_id')],
            'is_active' => ['boolean'],
        ]);
        $this->academic->updateProgramme($programme, ['name' => $data['name'], 'code' => $data['code'], 'is_active' => (bool) ($data['is_active'] ?? true)]);

        return $this->done('Programme saved.');
    }

    public function storeCourse(Request $request, int $programme): RedirectResponse
    {
        $profile = $this->programmeOrFail($request, $programme);
        $this->academic->createCourse($profile, $this->courseInput($request, $profile, null), $request->user('web')?->cms_staff_id);

        return $this->done('Course added. Now add its subjects and topics.');
    }

    public function updateCourse(Request $request, int $course): RedirectResponse
    {
        $row = $this->courseOrFail($request, $course);
        $profile = $this->programmeOrFail($request, (int) $row->class_id);
        $data = $this->courseInput($request, $profile, $course);
        $data['status'] = $request->validate(['status' => ['required', 'in:active,inactive,retired']])['status'];
        $this->academic->updateCourse($course, $data, $request->user('web')?->cms_staff_id);

        return $this->done('Course saved.');
    }

    public function curriculum(Request $request, int $course): Response
    {
        return Inertia::render('setup/Curriculum', $this->academic->curriculum($this->courseOrFail($request, $course)));
    }

    public function storeNode(Request $request, int $course): RedirectResponse
    {
        $row = $this->courseOrFail($request, $course);
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'names' => ['required', 'string', 'max:20000'],
            'discipline_id' => ['nullable', 'integer', Rule::exists(AdminTables::rule('acad_disciplines'), 'id')],
        ]);

        // One name per line, so a whole list of topics can be pasted in one go.
        $names = array_values(array_unique(array_filter(array_map(trim(...), preg_split('/\R/', $data['names']) ?: []), fn (string $n): bool => $n !== '')));
        $added = $this->academic->addNodes($row, isset($data['parent_id']) ? (int) $data['parent_id'] : null, $names, isset($data['discipline_id']) ? (int) $data['discipline_id'] : null);

        return $this->done($added === 1 ? 'Added.' : "{$added} added.");
    }

    public function updateNode(Request $request, int $node): RedirectResponse
    {
        $row = $this->academic->node($node);
        abort_if($row === null, 404);
        $this->courseOrFail($request, (int) $row->course_id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200', Rule::unique(AdminTables::rule('acad_curriculum_nodes'), 'name')->where('course_id', $row->course_id)->where('parent_key', $row->parent_id ?? 0)->ignore($node)],
            'code' => ['nullable', 'string', 'max:30'],
            'discipline_id' => ['nullable', 'integer', Rule::exists(AdminTables::rule('acad_disciplines'), 'id')],
            'is_active' => ['boolean'],
        ]);
        $this->academic->updateNode($node, [
            'name' => $data['name'], 'code' => $data['code'] ?? null,
            'discipline_id' => isset($data['discipline_id']) ? (int) $data['discipline_id'] : null, 'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return $this->done('Saved.');
    }

    public function lists(Request $request): Response
    {
        return Inertia::render('setup/Lists', $this->academic->lists($this->branchId($request)));
    }

    public function storeIntake(Request $request): RedirectResponse
    {
        $this->academic->saveIntake($this->branchId($request), null, $this->intakeInput($request));

        return $this->done('Intake added.');
    }

    public function updateIntake(Request $request, int $intake): RedirectResponse
    {
        $this->academic->saveIntake($this->branchId($request), $intake, $this->intakeInput($request));

        return $this->done('Intake saved.');
    }

    public function storeExamType(Request $request): RedirectResponse
    {
        $this->academic->saveExamType(null, $this->examTypeInput($request, null));

        return $this->done('Exam type added.');
    }

    public function updateExamType(Request $request, int $type): RedirectResponse
    {
        $this->academic->saveExamType($type, $this->examTypeInput($request, $type));

        return $this->done('Exam type saved.');
    }

    public function storeDiscipline(Request $request): RedirectResponse
    {
        $this->academic->saveDiscipline(null, $this->disciplineInput($request, null));

        return $this->done('Discipline added.');
    }

    public function updateDiscipline(Request $request, int $discipline): RedirectResponse
    {
        $this->academic->saveDiscipline($discipline, $this->disciplineInput($request, $discipline));

        return $this->done('Discipline saved.');
    }

    private function branchId(Request $request): int
    {
        $id = $this->activeBranch->id($request->user('web'));
        abort_if($id === null, 403, 'Choose a campus first.');

        return $id;
    }

    private function programmeOrFail(Request $request, int $programme): stdClass
    {
        $row = $this->academic->programme($this->branchId($request), $programme);
        abort_if($row === null, 404);

        return $row;
    }

    private function courseOrFail(Request $request, int $course): stdClass
    {
        $row = $this->academic->course($this->branchId($request), $course);
        abort_if($row === null, 404);

        return $row;
    }

    /** @return array<string, mixed> */
    private function courseInput(Request $request, stdClass $profile, ?int $courseId): array
    {
        $semester = $profile->calendar_type === 'semester';
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:'.self::COURSE_CODE, Rule::unique(AdminTables::rule('acad_courses'), 'course_code')->ignore($courseId)],
            'title' => ['required', 'string', 'max:200'],
            'professional_id' => ['required', 'integer', Rule::exists(AdminTables::rule('acad_professionals'), 'id')->where('class_id', $profile->id)],
            'term_id' => [$semester ? 'required' : 'nullable', 'integer', Rule::exists(AdminTables::rule('acad_professional_terms'), 'id')->where('professional_id', $request->integer('professional_id'))],
            'credit_hours' => ['nullable', 'numeric', 'min:0', 'max:99'],
        ], [
            'code.regex' => 'The Course ID is 3 to 20 letters, numbers or dashes, starting with a letter or number.',
            'code.unique' => 'Another course already has this Course ID.',
            'term_id.required' => 'Choose the semester.',
        ]);

        return [
            'course_code' => $data['code'],
            'title' => $data['title'],
            'professional_id' => (int) $data['professional_id'],
            'term_id' => $semester ? (int) $data['term_id'] : null,
            'credit_hours' => $semester ? ($data['credit_hours'] ?? null) : null,
        ];
    }

    /** @return array{session: string, start_date: string|null, end_date: string|null} */
    private function intakeInput(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        return ['session' => $data['name'], 'start_date' => $data['start_date'] ?? null, 'end_date' => $data['end_date'] ?? null];
    }

    /** @return array<string, mixed> */
    private function examTypeInput(Request $request, ?int $id): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique(AdminTables::rule('acad_exam_types'), 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:60', Rule::unique(AdminTables::rule('acad_exam_types'), 'name')->ignore($id)],
            'calendar' => ['required', 'in:annual,semester,any'],
            'is_resit' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        return ['code' => $data['code'], 'name' => $data['name'], 'calendar_type' => $data['calendar'], 'is_resit' => (int) ($data['is_resit'] ?? false), 'is_active' => (int) ($data['is_active'] ?? true)];
    }

    /** @return array<string, mixed> */
    private function disciplineInput(Request $request, ?int $id): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique(AdminTables::rule('acad_disciplines'), 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:150', Rule::unique(AdminTables::rule('acad_disciplines'), 'name')->ignore($id)],
            'is_active' => ['boolean'],
        ]);

        return ['code' => $data['code'], 'name' => $data['name'], 'is_active' => (int) ($data['is_active'] ?? true)];
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
