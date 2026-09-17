<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BookMarked,
    CircleAlert,
    Eye,
    EyeOff,
    ListChecks,
    Plus,
    Save,
    Send,
    Settings2,
    SlidersHorizontal,
    Tag as TagIcon,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import AnswersEditor from '@/components/qbank/AnswersEditor.vue';
import CandidatePreview from '@/components/qbank/CandidatePreview.vue';
import ChecksPanel from '@/components/qbank/ChecksPanel.vue';
import ItemsEditor from '@/components/qbank/ItemsEditor.vue';
import OptionsEditor from '@/components/qbank/OptionsEditor.vue';
import ReferencesEditor from '@/components/qbank/ReferencesEditor.vue';
import RichTextField from '@/components/qbank/RichTextField.vue';
import RubricEditor from '@/components/qbank/RubricEditor.vue';
import SettingsPanel from '@/components/qbank/SettingsPanel.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/questions';
import type {
    CourseOption,
    CurriculumNode,
    QuestionChecks,
    QuestionDraft,
    QuestionTypeInfo,
    StoredVersion,
} from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Question bank', href: index() }] },
});

const props = defineProps<{
    version: StoredVersion | null;
    reference: string | null;
    types: QuestionTypeInfo[];
    programmes: { id: number; name: string; code: string }[];
    courses: CourseOption[];
    disciplines: { id: number; code: string; name: string }[];
    tags: { id: number; name: string }[];
    cognitiveLevels: { id: number; name: string; description: string | null }[];
    difficultyLevels: { id: number; name: string }[];
    limits: {
        stemMin: number;
        stemMax: number;
        marksMax: number;
        referenceRequired: boolean;
    };
}>();

const draft = ref<QuestionDraft>(
    props.version
        ? {
              question_type_id: props.version.questionTypeId,
              course_id: props.version.courseId,
              node_id: props.version.nodeId,
              discipline_id: props.version.disciplineId,
              vignette: props.version.vignette,
              stem: props.version.stem,
              lead_in: props.version.leadIn,
              explanation: props.version.explanation,
              settings: props.version.settings ?? {},
              marks: props.version.marks,
              negative_marks: props.version.negativeMarks,
              cognitive_level_id: props.version.cognitiveLevelId,
              difficulty_level_id: props.version.difficultyLevelId,
              options: props.version.options,
              items: props.version.items,
              answers: props.version.answers,
              rubric: props.version.rubric,
              references: props.version.references,
              tag_ids: props.version.tagIds,
          }
        : {
              question_type_id: props.types[0]?.id ?? null,
              course_id: props.courses[0]?.id ?? null,
              node_id: null,
              discipline_id: null,
              vignette: null,
              stem: '',
              lead_in: null,
              explanation: null,
              settings: { ...props.types[0]?.defaultSettings },
              marks: 1,
              negative_marks: 0,
              cognitive_level_id: null,
              difficulty_level_id: null,
              options: [],
              items: [],
              answers: [],
              rubric: [],
              references: [],
              tag_ids: [],
          },
);

const programmeId = ref<number | null>(
    props.version !== null
        ? (props.courses.find((course) => course.id === props.version?.courseId)
              ?.programme_id ??
              props.programmes[0]?.id ??
              null)
        : (props.programmes[0]?.id ?? null),
);

const nodes = ref<CurriculumNode[]>([]);
const availableTags = ref([...props.tags]);
const newTag = ref('');
const checks = ref<QuestionChecks>({ errors: {}, warnings: [] });
const checking = ref(false);
const saving = ref(false);
const serverErrors = ref<Record<string, string>>({});
const showAnswers = ref(true);

const type = computed(
    () =>
        props.types.find((row) => row.id === draft.value.question_type_id) ??
        null,
);
const readOnly = computed(
    () => props.version !== null && !props.version.editable,
);
const topics = computed(() =>
    nodes.value.filter((node) => node.allows_questions),
);
const hasCourses = computed(() => props.courses.length > 0);

// The CMS structure decides the order: programme, then its Course IDs, then the topics inside.
const coursesOfProgramme = computed(() =>
    props.courses.filter((course) => course.programme_id === programmeId.value),
);

// Topics are shown with their parents (for MBBS: discipline → topic → subtopic).
const topicOptions = computed(() => {
    const byId = new Map(nodes.value.map((node) => [node.id, node]));
    const pathOf = (node: CurriculumNode): string => {
        const names: string[] = [node.name];
        let parent =
            node.parent_id === null ? undefined : byId.get(node.parent_id);
        while (parent) {
            names.unshift(parent.name);
            parent =
                parent.parent_id === null
                    ? undefined
                    : byId.get(parent.parent_id);
        }
        return names.join(' → ');
    };

    return topics.value.map((node) => ({
        id: node.id,
        label: pathOf(node),
        level: node.level,
    }));
});
const errorCount = computed(
    () => Object.values(checks.value.errors).flat().length,
);
const canSubmit = computed(
    () =>
        props.version !== null &&
        props.version.editable &&
        errorCount.value === 0 &&
        !checking.value,
);

// Only the shape of the question changes with the type; the text the author wrote stays.
watch(
    () => draft.value.question_type_id,
    (id, previous) => {
        const next = props.types.find((row) => row.id === id);
        if (!next || previous === undefined || id === previous) {
            return;
        }
        draft.value.settings = { ...next.defaultSettings };
        if (!next.hasOptions) {
            draft.value.options = [];
        }
        if (!next.hasItems) {
            draft.value.items = [];
        }
        if (!next.hasAcceptedAnswers) {
            draft.value.answers = [];
        }
        if (!next.supportsRubric) {
            draft.value.rubric = [];
        }
        if (!next.supportsNegativeMarks) {
            draft.value.negative_marks = 0;
        }
        if (next.code === 'true_false' && draft.value.options.length === 0) {
            draft.value.options = [
                {
                    label: 'A',
                    body: 'True',
                    is_correct: false,
                    weight: null,
                    feedback: null,
                    sort_order: 1,
                    is_position_locked: true,
                    item_index: null,
                },
                {
                    label: 'B',
                    body: 'False',
                    is_correct: false,
                    weight: null,
                    feedback: null,
                    sort_order: 2,
                    is_position_locked: true,
                    item_index: null,
                },
            ];
        }
    },
);

function csrf(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
}

async function loadTopics(courseId: number | null): Promise<void> {
    nodes.value = [];
    if (courseId === null) {
        return;
    }
    const response = await fetch(
        `/questions/curriculum?course_id=${courseId}`,
        {
            headers: { Accept: 'application/json' },
        },
    );
    if (response.ok) {
        nodes.value = (
            (await response.json()) as { nodes: CurriculumNode[] }
        ).nodes;
    }
}

watch(programmeId, (id, previous) => {
    if (previous === undefined || id === previous) {
        return;
    }
    draft.value.course_id = coursesOfProgramme.value[0]?.id ?? null;
    draft.value.node_id = null;
});

watch(
    () => draft.value.course_id,
    (courseId, previous) => {
        if (previous !== undefined && courseId !== previous) {
            draft.value.node_id = null;
        }
        void loadTopics(courseId);
    },
    { immediate: true },
);

// The editor shows the same checks the server applies on submission, including the missing fields.
let checkTimer: number | undefined;
watch(
    draft,
    () => {
        window.clearTimeout(checkTimer);
        checking.value = true;
        checkTimer = window.setTimeout(async () => {
            try {
                const response = await fetch('/questions/check', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': csrf(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(draft.value),
                });
                const payload = (await response.json()) as QuestionChecks & {
                    errors?: Record<string, string[]>;
                };

                checks.value = response.ok
                    ? payload
                    : { errors: payload.errors ?? {}, warnings: [] };
            } finally {
                checking.value = false;
            }
        }, 500);
    },
    { deep: true, immediate: true },
);

async function addTag(): Promise<void> {
    const name = newTag.value.trim();
    if (name === '') {
        return;
    }
    const response = await fetch('/questions/tags', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrf(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ name }),
    });
    if (!response.ok) {
        return;
    }
    const tag = (await response.json()) as { id: number; name: string };
    if (!availableTags.value.some((row) => row.id === tag.id)) {
        availableTags.value = [...availableTags.value, tag].sort((a, b) =>
            a.name.localeCompare(b.name),
        );
    }
    if (!draft.value.tag_ids.includes(tag.id)) {
        draft.value.tag_ids = [...draft.value.tag_ids, tag.id];
    }
    newTag.value = '';
}

function save(): void {
    saving.value = true;
    serverErrors.value = {};
    const options = {
        preserveScroll: true,
        onError: (errors: Record<string, string>) =>
            (serverErrors.value = errors),
        onFinish: () => (saving.value = false),
    };

    if (props.version) {
        router.put(
            `/questions/${props.version.questionId}/versions/${props.version.id}`,
            draft.value,
            options,
        );
    } else {
        router.post('/questions', draft.value, options);
    }
}

function submit(): void {
    if (!props.version) {
        return;
    }
    saving.value = true;
    router.post(
        `/questions/${props.version.questionId}/versions/${props.version.id}/submit`,
        {},
        {
            onError: (errors: Record<string, string>) =>
                (serverErrors.value = errors),
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <Head :title="version ? `Edit ${reference}` : 'New question'" />

    <div class="flex flex-col">
        <!-- Actions stay in reach while writing a long question. -->
        <div
            class="bg-background/95 supports-backdrop-filter:bg-background/75 sticky top-0 z-10 border-b px-4 py-3 backdrop-blur"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="truncate text-lg font-semibold tracking-tight">
                        {{
                            version
                                ? `${reference} · version ${version.versionNo}`
                                : 'New question'
                        }}
                    </h1>
                    <p class="text-muted-foreground text-sm">
                        {{
                            version
                                ? `${version.statusLabel}. Once it is sent for review its content is frozen.`
                                : 'Write the question, choose where it belongs, then save it as a draft.'
                        }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge v-if="version" variant="secondary">{{
                        version.statusLabel
                    }}</Badge>
                    <Badge v-if="checking" variant="outline">Checking…</Badge>
                    <Badge
                        v-else-if="errorCount > 0"
                        variant="destructive"
                        data-test="error-count"
                    >
                        {{ errorCount }} to fix
                    </Badge>
                    <Badge
                        v-else
                        variant="outline"
                        class="border-green-600 text-green-700 dark:text-green-400"
                    >
                        No problems
                    </Badge>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="saving || readOnly || !hasCourses"
                        data-test="save-draft"
                        @click="save"
                    >
                        <Save /> Save draft
                    </Button>
                    <Button
                        type="button"
                        :disabled="!canSubmit || saving"
                        data-test="submit-question"
                        @click="submit"
                    >
                        <Send /> Send for review
                    </Button>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-6 p-4">
            <p
                v-if="readOnly"
                class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0" />
                This version is {{ version?.statusLabel.toLowerCase() }}, so it
                cannot be changed. Open the question and start a new version to
                make changes.
            </p>

            <div
                v-if="!hasCourses"
                class="grid gap-2 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                data-test="no-courses"
            >
                <strong class="font-medium"
                    >There is no course to write for yet.</strong
                >
                <p>
                    Questions hang on the academic structure kept in the CMS. In
                    the CMS, open
                    <em>Academics → Course (Course ID)</em> to add a course for
                    this campus, then <em>Academics → Curriculum</em> to add the
                    topics inside it. Topics at a level that takes questions
                    then appear here.
                </p>
                <p>
                    A course that is <em>retired</em> is never offered here, and
                    a course counts only once it has a topic at a level that
                    takes questions.
                </p>
                <p>
                    If you do have active courses, your exam access may be
                    limited to other programmes — check
                    <em>Question Bank &amp; Exams → Exam Access</em> in the CMS.
                </p>
            </div>

            <InputError
                v-for="(message, key) in serverErrors"
                :key="key"
                :message="message"
            />

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_23rem]">
                <div class="grid min-w-0 gap-6">
                    <!-- Where the question belongs -->
                    <section class="rounded-xl border shadow-xs">
                        <header
                            class="flex items-center gap-2 border-b px-4 py-3"
                        >
                            <BookMarked class="text-muted-foreground size-4" />
                            <h2 class="font-medium">Where it belongs</h2>
                        </header>
                        <div class="grid gap-4 p-4 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="programme">Programme *</Label>
                                <select
                                    id="programme"
                                    v-model.number="programmeId"
                                    class="border-input bg-background h-9 rounded-md border px-2 text-sm disabled:opacity-50"
                                    :disabled="
                                        readOnly || programmes.length === 0
                                    "
                                    data-test="programme"
                                >
                                    <option
                                        v-if="programmes.length === 0"
                                        :value="null"
                                    >
                                        No programme available
                                    </option>
                                    <option
                                        v-for="programme in programmes"
                                        :key="programme.id"
                                        :value="programme.id"
                                    >
                                        {{ programme.name }} ({{
                                            programme.code
                                        }})
                                    </option>
                                </select>
                                <p class="text-muted-foreground text-xs">
                                    MBBS, BDS or DPT, as the CMS academic
                                    structure has them.
                                </p>
                            </div>

                            <div class="grid gap-1.5">
                                <Label for="course">Course ID *</Label>
                                <select
                                    id="course"
                                    v-model.number="draft.course_id"
                                    class="border-input bg-background h-9 rounded-md border px-2 text-sm disabled:opacity-50"
                                    :disabled="readOnly || !hasCourses"
                                >
                                    <option
                                        v-if="coursesOfProgramme.length === 0"
                                        :value="null"
                                    >
                                        No course in this programme
                                    </option>
                                    <option
                                        v-for="course in coursesOfProgramme"
                                        :key="course.id"
                                        :value="course.id"
                                    >
                                        {{ course.code }} — {{ course.title }}
                                    </option>
                                </select>
                                <InputError
                                    v-for="(message, i) in checks.errors[
                                        'course_id'
                                    ] ?? []"
                                    :key="i"
                                    :message="message"
                                />
                            </div>

                            <div class="grid gap-1.5">
                                <Label for="topic">Topic *</Label>
                                <select
                                    id="topic"
                                    v-model.number="draft.node_id"
                                    class="border-input bg-background h-9 rounded-md border px-2 text-sm disabled:opacity-50"
                                    :disabled="readOnly || topics.length === 0"
                                >
                                    <option :value="null">Choose…</option>
                                    <option
                                        v-for="topic in topicOptions"
                                        :key="topic.id"
                                        :value="topic.id"
                                    >
                                        {{ topic.label }}
                                    </option>
                                </select>
                                <p
                                    v-if="
                                        hasCourses &&
                                        draft.course_id !== null &&
                                        topics.length === 0
                                    "
                                    class="text-muted-foreground text-xs"
                                >
                                    This course has no topic that takes
                                    questions yet. Add one in the CMS curriculum
                                    (Academics → Curriculum).
                                </p>
                                <InputError
                                    v-for="(message, i) in checks.errors[
                                        'node_id'
                                    ] ?? []"
                                    :key="i"
                                    :message="message"
                                />
                            </div>

                            <div class="grid gap-1.5">
                                <Label for="type">Type of question *</Label>
                                <select
                                    id="type"
                                    v-model.number="draft.question_type_id"
                                    class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                    :disabled="readOnly"
                                    data-test="question-type"
                                >
                                    <option
                                        v-for="row in types"
                                        :key="row.id"
                                        :value="row.id"
                                    >
                                        {{ row.name }}
                                    </option>
                                </select>
                                <p
                                    v-if="type"
                                    class="text-muted-foreground text-xs"
                                >
                                    {{ type.description }}
                                </p>
                            </div>

                            <div class="grid gap-1.5">
                                <Label for="discipline"
                                    >Discipline / subject</Label
                                >
                                <select
                                    id="discipline"
                                    v-model.number="draft.discipline_id"
                                    class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                    :disabled="readOnly"
                                >
                                    <option :value="null">
                                        From the topic
                                    </option>
                                    <option
                                        v-for="row in disciplines"
                                        :key="row.id"
                                        :value="row.id"
                                    >
                                        {{ row.name }}
                                    </option>
                                </select>
                                <p class="text-muted-foreground text-xs">
                                    Blueprints count questions by discipline;
                                    leave it on the topic's own unless this
                                    question belongs elsewhere.
                                </p>
                            </div>

                            <div class="grid gap-1.5">
                                <Label for="marks">Marks *</Label>
                                <Input
                                    id="marks"
                                    v-model.number="draft.marks"
                                    type="number"
                                    step="0.5"
                                    min="0"
                                    :max="limits.marksMax"
                                    :disabled="readOnly"
                                />
                                <InputError
                                    v-for="(message, i) in checks.errors[
                                        'marks'
                                    ] ?? []"
                                    :key="i"
                                    :message="message"
                                />
                            </div>

                            <div
                                v-if="type?.supportsNegativeMarks"
                                class="grid gap-1.5"
                            >
                                <Label for="negative">Negative marks</Label>
                                <Input
                                    id="negative"
                                    v-model.number="draft.negative_marks"
                                    type="number"
                                    step="0.25"
                                    min="0"
                                    :disabled="readOnly"
                                />
                                <InputError
                                    v-for="(message, i) in checks.errors[
                                        'negative_marks'
                                    ] ?? []"
                                    :key="i"
                                    :message="message"
                                />
                            </div>
                        </div>
                    </section>

                    <!-- The question text -->
                    <section class="rounded-xl border shadow-xs">
                        <header
                            class="flex items-center gap-2 border-b px-4 py-3"
                        >
                            <ListChecks class="text-muted-foreground size-4" />
                            <h2 class="font-medium">The question</h2>
                        </header>
                        <div class="grid gap-4 p-4">
                            <RichTextField
                                id="vignette"
                                v-model="draft.vignette"
                                label="Clinical scenario (optional)"
                                hint="The patient story. Basic formatting and pictures are kept; anything unsafe is removed when it is saved."
                                :rows="4"
                                allow-images
                                :disabled="readOnly"
                            />
                            <RichTextField
                                id="stem"
                                v-model="draft.stem"
                                label="Question"
                                required
                                :rows="4"
                                allow-images
                                :counter="{
                                    min: limits.stemMin,
                                    max: limits.stemMax,
                                }"
                                :errors="checks.errors['stem']"
                                :disabled="readOnly"
                            />
                            <div class="grid gap-1.5">
                                <Label for="lead-in">Lead-in</Label>
                                <Input
                                    id="lead-in"
                                    :model-value="draft.lead_in ?? ''"
                                    maxlength="500"
                                    placeholder="Which of the following is the most likely diagnosis?"
                                    :disabled="readOnly"
                                    @update:model-value="
                                        draft.lead_in =
                                            $event === ''
                                                ? null
                                                : String($event)
                                    "
                                />
                                <p class="text-muted-foreground text-xs">
                                    The actual question, asked positively.
                                </p>
                            </div>
                        </div>
                    </section>

                    <OptionsEditor
                        v-if="type?.hasOptions"
                        v-model="draft.options"
                        :type="type"
                        :checks="checks"
                        :disabled="readOnly"
                        :partial-credit="
                            type.supportsPartialCredit &&
                            draft.settings['partial_credit'] === true
                        "
                    />

                    <ItemsEditor
                        v-if="type?.hasItems"
                        v-model="draft.items"
                        :options="draft.options"
                        :type="type"
                        :checks="checks"
                        :disabled="readOnly"
                    />

                    <AnswersEditor
                        v-if="type?.hasAcceptedAnswers"
                        v-model="draft.answers"
                        :items="draft.items"
                        :type="type"
                        :checks="checks"
                        :disabled="readOnly"
                    />

                    <RubricEditor
                        v-if="type?.supportsRubric"
                        v-model="draft.rubric"
                        :marks="draft.marks"
                        :checks="checks"
                        :disabled="readOnly"
                    />

                    <!-- Why the answer is right -->
                    <section class="rounded-xl border shadow-xs">
                        <header
                            class="flex items-center gap-2 border-b px-4 py-3"
                        >
                            <SlidersHorizontal
                                class="text-muted-foreground size-4"
                            />
                            <h2 class="font-medium">Explanation and level</h2>
                        </header>
                        <div class="grid gap-4 p-4">
                            <RichTextField
                                id="explanation"
                                v-model="draft.explanation"
                                label="Explanation"
                                hint="Why the answer is right, and why the others are not. Reviewers read this first."
                                :rows="3"
                                allow-images
                                :disabled="readOnly"
                            />
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-1.5">
                                    <Label for="cognitive"
                                        >Level of thinking</Label
                                    >
                                    <select
                                        id="cognitive"
                                        v-model.number="
                                            draft.cognitive_level_id
                                        "
                                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                        :disabled="readOnly"
                                    >
                                        <option :value="null">
                                            Not chosen
                                        </option>
                                        <option
                                            v-for="level in cognitiveLevels"
                                            :key="level.id"
                                            :value="level.id"
                                        >
                                            {{ level.name }}
                                        </option>
                                    </select>
                                </div>
                                <div class="grid gap-1.5">
                                    <Label for="difficulty"
                                        >Expected difficulty</Label
                                    >
                                    <select
                                        id="difficulty"
                                        v-model.number="
                                            draft.difficulty_level_id
                                        "
                                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                        :disabled="readOnly"
                                    >
                                        <option :value="null">
                                            Not chosen
                                        </option>
                                        <option
                                            v-for="level in difficultyLevels"
                                            :key="level.id"
                                            :value="level.id"
                                        >
                                            {{ level.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </section>

                    <ReferencesEditor
                        v-model="draft.references"
                        :required="limits.referenceRequired"
                        :checks="checks"
                        :disabled="readOnly"
                    />

                    <SettingsPanel
                        v-if="type"
                        v-model="draft.settings"
                        :type="type"
                        :disabled="readOnly"
                    />

                    <!-- Tags -->
                    <section class="rounded-xl border shadow-xs">
                        <header
                            class="flex items-center gap-2 border-b px-4 py-3"
                        >
                            <TagIcon class="text-muted-foreground size-4" />
                            <h2 class="font-medium">Tags</h2>
                        </header>
                        <div class="grid gap-3 p-4">
                            <div
                                v-if="availableTags.length > 0"
                                class="flex flex-wrap gap-2"
                            >
                                <label
                                    v-for="tag in availableTags"
                                    :key="tag.id"
                                    class="hover:bg-accent flex cursor-pointer items-center gap-2 rounded-md border px-2 py-1 text-sm"
                                    :class="
                                        draft.tag_ids.includes(tag.id)
                                            ? 'border-primary bg-accent'
                                            : ''
                                    "
                                >
                                    <input
                                        type="checkbox"
                                        :checked="
                                            draft.tag_ids.includes(tag.id)
                                        "
                                        :disabled="readOnly"
                                        @change="
                                            draft.tag_ids = (
                                                $event.target as HTMLInputElement
                                            ).checked
                                                ? [...draft.tag_ids, tag.id]
                                                : draft.tag_ids.filter(
                                                      (id) => id !== tag.id,
                                                  )
                                        "
                                    />
                                    {{ tag.name }}
                                </label>
                            </div>
                            <p v-else class="text-muted-foreground text-sm">
                                No tags in this campus yet.
                            </p>
                            <form
                                v-if="!readOnly"
                                class="flex max-w-sm gap-2"
                                @submit.prevent="addTag"
                            >
                                <Input
                                    v-model="newTag"
                                    maxlength="60"
                                    placeholder="New tag, e.g. ECG"
                                    aria-label="New tag"
                                    data-test="new-tag"
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    :disabled="newTag.trim() === ''"
                                >
                                    <Plus /> Add
                                </Button>
                            </form>
                        </div>
                    </section>
                </div>

                <!-- Checks and preview stay beside the question while scrolling. -->
                <div class="grid content-start gap-4 xl:sticky xl:top-24">
                    <ChecksPanel :checks="checks" :checking="checking" />

                    <div class="grid gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="justify-self-start"
                            @click="showAnswers = !showAnswers"
                        >
                            <component :is="showAnswers ? EyeOff : Eye" />
                            {{
                                showAnswers
                                    ? 'Hide the answer key'
                                    : 'Show the answer key'
                            }}
                        </Button>
                        <CandidatePreview
                            :draft="draft"
                            :type="type"
                            :show-answers="showAnswers"
                        />
                    </div>

                    <p
                        class="text-muted-foreground flex items-start gap-2 text-xs"
                    >
                        <Settings2 class="mt-0.5 size-3.5 shrink-0" />
                        Roles, exam access and the audit log are managed in the
                        CMS; this screen only writes questions for
                        {{
                            courses.find(
                                (course) => course.id === draft.course_id,
                            )?.code ?? 'your campus'
                        }}.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
