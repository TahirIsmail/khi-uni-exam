<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { CalendarClock, Landmark, ListChecks, Shuffle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ExamJourney from '@/components/exam/ExamJourney.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/exams';
import type { ExamChoices, ExaminationDetail } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Examinations', href: index() }] },
});

const props = defineProps<
    {
        /** Null while a new examination is being set up. */
        examination: ExaminationDetail | null;
        /** Once the blueprint is submitted the course, examination and total marks are fixed. */
        fixed: boolean;
    } & ExamChoices
>();

const editing = props.examination !== null;

const yearKey = (professionalId: number, termId: number | null): string =>
    `${professionalId}${termId === null ? '' : `-${termId}`}`;

// KMU names an examination by where it sits: Programme → Year / Semester → Examination → Course ID.
const programmeId = ref<number | null>(
    props.examination?.programmeId ?? props.programmes[0]?.id ?? null,
);
const yearsOfProgramme = computed(() =>
    props.years.filter((year) => year.programme_id === programmeId.value),
);
const yearId = ref<string | null>(
    props.examination
        ? yearKey(props.examination.professionalId, props.examination.termId)
        : (yearsOfProgramme.value[0]?.id ?? null),
);
const selectedYear = computed(
    () => props.years.find((year) => year.id === yearId.value) ?? null,
);
const coursesOfYear = computed(() =>
    props.courses.filter(
        (course) =>
            course.programme_id === programmeId.value &&
            selectedYear.value !== null &&
            course.professional_id === selectedYear.value.professional_id &&
            course.term_id === selectedYear.value.term_id,
    ),
);
const examTypesOfProgramme = computed(() => {
    const calendar =
        programmeId.value === null
            ? null
            : props.programmeCalendars[programmeId.value];

    return props.examTypes.filter(
        (row) => row.calendar === calendar || row.calendar === 'any',
    );
});

const form = useForm({
    title: props.examination?.title ?? '',
    course_id: (props.examination?.courseId ?? null) as number | null,
    exam_type_id: (props.examination?.examTypeId ?? null) as number | null,
    intake_id: (props.examination?.intakeId ?? null) as number | null,
    starts_at: props.examination?.startsAt ?? '',
    closes_at: props.examination?.closesAt ?? '',
    shared_pin: props.examination?.sharedPin ?? '',
    question_count: '' as number | '',
    duration_minutes:
        props.examination?.durationMinutes ?? props.defaults.durationMinutes,
    total_marks: (props.examination?.totalMarks ?? '') as number | '',
    pass_percentage:
        props.examination?.passPercentage ?? props.defaults.passPercentage,
    negative_marking: props.examination?.negativeMarking ?? false,
    negative_fraction: (props.examination?.negativeFraction ?? '') as
        | number
        | '',
    instructions: props.examination?.instructions ?? '',
});

// A new examination starts in the first course of the first year of the first programme.
if (!editing) {
    form.course_id = coursesOfYear.value[0]?.id ?? null;
    form.exam_type_id = examTypesOfProgramme.value[0]?.id ?? null;
}

watch(programmeId, (id, previous) => {
    if (id === previous) {
        return;
    }
    yearId.value = yearsOfProgramme.value[0]?.id ?? null;
    if (
        !examTypesOfProgramme.value.some((row) => row.id === form.exam_type_id)
    ) {
        form.exam_type_id = examTypesOfProgramme.value[0]?.id ?? null;
    }
});

watch(yearId, (id, previous) => {
    if (id === previous) {
        return;
    }
    form.course_id = coursesOfYear.value[0]?.id ?? null;
});

// The title writes itself from the chain until somebody writes their own.
const titleTouched = ref(editing);
const suggestedTitle = computed(() => {
    const programme = props.programmes.find(
        (row) => row.id === programmeId.value,
    );
    const type = props.examTypes.find((row) => row.id === form.exam_type_id);
    const course = props.courses.find((row) => row.id === form.course_id);
    if (!programme || !selectedYear.value || !type || !course) {
        return '';
    }
    const year = form.starts_at
        ? form.starts_at.slice(0, 4)
        : String(new Date().getFullYear());

    return `${programme.name} ${selectedYear.value.name} ${type.name} Examination ${year} — ${course.code} ${course.title}`;
});
watch(
    suggestedTitle,
    (title) => {
        if (!titleTouched.value) {
            form.title = title;
        }
    },
    { immediate: true },
);

const passMarks = computed(() => {
    const total = Number(form.total_marks);
    const percent = Number(form.pass_percentage);

    return Number.isFinite(total) && Number.isFinite(percent)
        ? Math.round(((total * percent) / 100) * 100) / 100
        : 0;
});

const hasCourses = computed(() => props.courses.length > 0);

// "This many questions from the course" sets the paper up in one step: a Super Admin's alone.
const page = usePage();
const canDrawInOneStep = computed(
    () => !editing && page.props.auth.can?.manageSetup === true,
);

function newPin(): void {
    const digits = new Uint32Array(1);
    crypto.getRandomValues(digits);
    form.shared_pin = String(digits[0] % 1_000_000).padStart(6, '0');
}

function submit(): void {
    if (props.examination) {
        form.put(`/exams/${props.examination.id}`);
    } else {
        form.post('/exams');
    }
}

const select =
    'border-input bg-background h-9 w-full min-w-0 rounded-md border px-2 text-sm disabled:opacity-50';
</script>

<template>
    <Head :title="editing ? 'Change examination' : 'New examination'" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="
                editing
                    ? `${examination?.reference} — details`
                    : 'New examination'
            "
            :description="
                editing
                    ? 'What the examination is, when it is held and how it is marked.'
                    : 'One examination is one sitting of one Course ID. You plan what the paper contains next, in its blueprint.'
            "
        />

        <ExamJourney
            :stage="editing ? 'draft' : 'new'"
            :exam-id="examination?.id"
        />

        <p
            v-if="!hasCourses"
            class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
            data-test="no-courses"
        >
            There is no Course ID you can set an examination for yet. Course IDs
            are added under Setup → Programmes &amp; courses, and each one has
            to sit in a year.
        </p>

        <form class="grid gap-6" data-test="exam-form" @submit.prevent="submit">
            <section class="rounded-xl border shadow-xs">
                <header class="flex items-center gap-2 border-b px-4 py-3">
                    <Landmark class="text-muted-foreground size-4" />
                    <h2 class="font-medium">1. What it is</h2>
                    <span class="text-muted-foreground text-xs"
                        >Programme → Year / Semester → Examination → Module /
                        Subject</span
                    >
                </header>
                <div
                    class="grid grid-cols-1 content-start gap-x-4 gap-y-4 p-4 md:grid-cols-2"
                >
                    <div class="grid min-w-0 content-start gap-1.5">
                        <Label for="programme">Programme *</Label>
                        <select
                            id="programme"
                            v-model.number="programmeId"
                            :class="select"
                            :disabled="fixed || programmes.length === 0"
                            data-test="programme"
                        >
                            <option
                                v-for="row in programmes"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.name }}
                            </option>
                        </select>
                    </div>

                    <div class="grid min-w-0 content-start gap-1.5">
                        <Label for="year">Year / Semester *</Label>
                        <select
                            id="year"
                            v-model="yearId"
                            :class="select"
                            :disabled="fixed || yearsOfProgramme.length === 0"
                            data-test="year"
                        >
                            <option
                                v-for="row in yearsOfProgramme"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.name }}
                            </option>
                        </select>
                    </div>

                    <div class="grid min-w-0 content-start gap-1.5">
                        <Label for="exam-type">Examination *</Label>
                        <select
                            id="exam-type"
                            v-model.number="form.exam_type_id"
                            :class="select"
                            :disabled="fixed"
                            data-test="exam-type"
                        >
                            <option
                                v-for="row in examTypesOfProgramme"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.exam_type_id" />
                    </div>

                    <div class="grid min-w-0 content-start gap-1.5">
                        <Label for="course"
                            >Module / Subject (Course ID) *</Label
                        >
                        <select
                            id="course"
                            v-model.number="form.course_id"
                            :class="select"
                            :disabled="fixed || coursesOfYear.length === 0"
                            data-test="course"
                        >
                            <option
                                v-if="coursesOfYear.length === 0"
                                :value="null"
                            >
                                No course in this year
                            </option>
                            <option
                                v-for="row in coursesOfYear"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.code }} — {{ row.title }}
                            </option>
                        </select>
                        <InputError :message="form.errors.course_id" />
                    </div>

                    <div
                        class="grid min-w-0 content-start gap-1.5 md:col-span-2"
                    >
                        <Label for="title">Title</Label>
                        <Input
                            id="title"
                            v-model="form.title"
                            maxlength="200"
                            data-test="title"
                            @input="titleTouched = true"
                        />
                        <p class="text-muted-foreground text-xs">
                            Written from the choices above; change it if the
                            university names it differently.
                        </p>
                        <InputError :message="form.errors.title" />
                    </div>

                    <div
                        v-if="intakes.length > 0"
                        class="grid min-w-0 content-start gap-1.5"
                    >
                        <Label for="intake">Academic session</Label>
                        <select
                            id="intake"
                            v-model.number="form.intake_id"
                            :class="select"
                            data-test="intake"
                        >
                            <option :value="null">Not set</option>
                            <option
                                v-for="row in intakes"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.intake_id" />
                    </div>
                </div>
                <p
                    v-if="fixed"
                    class="text-muted-foreground border-t px-4 py-3 text-xs"
                >
                    The blueprint has been submitted, so the course, examination
                    and total marks are fixed. Ask the approving committee to
                    send it back if they have to change.
                </p>
            </section>

            <section class="rounded-xl border shadow-xs">
                <header class="flex items-center gap-2 border-b px-4 py-3">
                    <CalendarClock class="text-muted-foreground size-4" />
                    <h2 class="font-medium">2. When and how long</h2>
                </header>
                <div
                    class="grid grid-cols-1 content-start gap-x-4 gap-y-4 p-4 md:grid-cols-2"
                >
                    <div class="grid min-w-0 content-start gap-1.5">
                        <Label for="starts-at">Date and start time</Label>
                        <Input
                            id="starts-at"
                            v-model="form.starts_at"
                            type="datetime-local"
                            data-test="starts-at"
                        />
                        <p class="text-muted-foreground text-xs">
                            In {{ timezone.replace('_', ' ') }} time. It can be
                            left empty and set later.
                        </p>
                        <InputError :message="form.errors.starts_at" />
                    </div>
                    <div class="grid min-w-0 content-start gap-1.5">
                        <Label for="duration">Duration (minutes) *</Label>
                        <Input
                            id="duration"
                            v-model.number="form.duration_minutes"
                            type="number"
                            :min="limits.durationMin"
                            :max="limits.durationMax"
                            step="1"
                            data-test="duration"
                        />
                        <InputError :message="form.errors.duration_minutes" />
                    </div>
                    <div class="grid min-w-0 content-start gap-1.5">
                        <Label for="closes-at">Closes at</Label>
                        <Input
                            id="closes-at"
                            v-model="form.closes_at"
                            type="datetime-local"
                            data-test="closes-at"
                        />
                        <p class="text-muted-foreground text-xs">
                            Optional. Candidates can start only from the start
                            time until this, and nobody's time runs past it.
                        </p>
                        <InputError :message="form.errors.closes_at" />
                    </div>
                    <div class="grid min-w-0 content-start gap-1.5">
                        <Label for="shared-pin">Exam PIN for everyone</Label>
                        <div class="flex gap-2">
                            <Input
                                id="shared-pin"
                                v-model="form.shared_pin"
                                inputmode="numeric"
                                maxlength="10"
                                placeholder="e.g. 482915"
                                data-test="shared-pin"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                @click="newPin"
                                >Make one</Button
                            >
                        </div>
                        <p class="text-muted-foreground text-xs">
                            Optional. Every candidate signs in with their
                            candidate number and this PIN, with no check-in.
                            Empty: each candidate gets their own PIN at
                            check-in.
                        </p>
                        <InputError :message="form.errors.shared_pin" />
                    </div>
                </div>
            </section>

            <section class="rounded-xl border shadow-xs">
                <header class="flex items-center gap-2 border-b px-4 py-3">
                    <ListChecks class="text-muted-foreground size-4" />
                    <h2 class="font-medium">3. Marks and marking</h2>
                </header>
                <div
                    class="grid grid-cols-1 content-start gap-x-4 gap-y-4 p-4 md:grid-cols-2"
                >
                    <div class="grid min-w-0 content-start gap-1.5">
                        <Label for="total">Total marks *</Label>
                        <Input
                            id="total"
                            v-model.number="form.total_marks"
                            type="number"
                            min="1"
                            :max="limits.marksMax"
                            step="0.5"
                            :disabled="fixed"
                            data-test="total-marks"
                        />
                        <p class="text-muted-foreground text-xs">
                            The blueprint has to add up to exactly this.
                        </p>
                        <InputError :message="form.errors.total_marks" />
                    </div>
                    <div class="grid min-w-0 content-start gap-1.5">
                        <Label for="pass">Pass mark (%) *</Label>
                        <Input
                            id="pass"
                            v-model.number="form.pass_percentage"
                            type="number"
                            min="0"
                            max="100"
                            step="0.5"
                            data-test="pass-percentage"
                        />
                        <p
                            class="text-muted-foreground text-xs"
                            data-test="pass-marks"
                        >
                            {{
                                form.total_marks
                                    ? `A candidate passes with ${passMarks} of ${form.total_marks} marks.`
                                    : 'Set the total marks to see what that comes to.'
                            }}
                        </p>
                        <InputError :message="form.errors.pass_percentage" />
                    </div>

                    <div class="grid min-w-0 content-start gap-2 md:col-span-2">
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                v-model="form.negative_marking"
                                type="checkbox"
                                class="size-4"
                                data-test="negative-marking"
                            />
                            A wrong answer loses marks (negative marking)
                        </label>
                        <div
                            v-if="form.negative_marking"
                            class="grid max-w-sm gap-1.5"
                        >
                            <Label for="fraction"
                                >Share of the question's marks lost</Label
                            >
                            <Input
                                id="fraction"
                                v-model.number="form.negative_fraction"
                                type="number"
                                min="0.05"
                                max="1"
                                step="0.05"
                                placeholder="e.g. 0.25"
                                data-test="negative-fraction"
                            />
                            <p class="text-muted-foreground text-xs">
                                0.25 means a wrong answer costs a quarter of the
                                question's marks. Leave it empty to use the
                                negative marks each question carries.
                            </p>
                            <InputError
                                :message="form.errors.negative_fraction"
                            />
                        </div>
                    </div>

                    <div
                        class="grid min-w-0 content-start gap-1.5 md:col-span-2"
                    >
                        <Label for="instructions"
                            >Instructions to candidates</Label
                        >
                        <textarea
                            id="instructions"
                            v-model="form.instructions"
                            class="border-input bg-background min-h-24 rounded-md border p-2 text-sm"
                            maxlength="5000"
                            placeholder="Shown to each candidate before they start, e.g. how to answer, what is allowed."
                            data-test="instructions"
                        />
                        <InputError :message="form.errors.instructions" />
                    </div>
                </div>
            </section>

            <section
                v-if="canDrawInOneStep"
                class="rounded-xl border shadow-xs"
                data-test="draw-section"
            >
                <header class="flex items-center gap-2 border-b px-4 py-3">
                    <Shuffle class="text-muted-foreground size-4" />
                    <h2 class="font-medium">4. Questions</h2>
                </header>
                <div class="grid max-w-md gap-1.5 p-4">
                    <Label for="question-count"
                        >Questions to take from this course</Label
                    >
                    <Input
                        id="question-count"
                        v-model.number="form.question_count"
                        type="number"
                        min="1"
                        max="500"
                        data-test="question-count"
                    />
                    <p class="text-muted-foreground text-xs">
                        Optional. That many questions are picked at random from
                        the whole course — never one another examination has
                        used — the total marks shared equally, shown in a
                        different order to every candidate, and the paper is
                        published straight away. Empty: plan the blueprint and
                        paper step by step.
                    </p>
                    <InputError :message="form.errors.question_count" />
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    type="submit"
                    :disabled="form.processing || !hasCourses"
                    data-test="save-exam"
                >
                    {{
                        editing
                            ? 'Save changes'
                            : form.question_count
                              ? 'Save and draw the questions'
                              : 'Save and plan the blueprint'
                    }}
                </Button>
                <Button as-child variant="ghost">
                    <Link
                        :href="editing ? `/exams/${examination?.id}` : index()"
                        >Cancel</Link
                    >
                </Button>
            </div>
        </form>
    </div>
</template>
