<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Eye, Save, Send } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CandidatePreview from '@/components/qbank/CandidatePreview.vue';
import AnswersEditor from '@/components/qbank/AnswersEditor.vue';
import ChecksPanel from '@/components/qbank/ChecksPanel.vue';
import ItemsEditor from '@/components/qbank/ItemsEditor.vue';
import OptionsEditor from '@/components/qbank/OptionsEditor.vue';
import ReferencesEditor from '@/components/qbank/ReferencesEditor.vue';
import RichTextField from '@/components/qbank/RichTextField.vue';
import RubricEditor from '@/components/qbank/RubricEditor.vue';
import SettingsPanel from '@/components/qbank/SettingsPanel.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
    courses: CourseOption[];
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

const nodes = ref<CurriculumNode[]>([]);
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
                        'X-XSRF-TOKEN': decodeURIComponent(
                            document.cookie
                                .split('; ')
                                .find((row) => row.startsWith('XSRF-TOKEN='))
                                ?.split('=')[1] ?? '',
                        ),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(draft.value),
                });
                if (response.ok) {
                    checks.value = (await response.json()) as QuestionChecks;
                }
            } finally {
                checking.value = false;
            }
        }, 600);
    },
    { deep: true, immediate: true },
);

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

const canSubmit = computed(
    () =>
        props.version !== null &&
        props.version.editable &&
        Object.keys(checks.value.errors).length === 0 &&
        !checking.value,
);
</script>

<template>
    <Head :title="version ? `Edit ${reference}` : 'New question'" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="
                    version
                        ? `${reference} — version ${version.versionNo}`
                        : 'New question'
                "
                :description="
                    version
                        ? `${version.statusLabel}. Saving keeps this version; once it is sent for review its content is frozen.`
                        : 'Write the question, choose where it belongs, and save it as a draft.'
                "
            />
            <div class="flex flex-wrap items-center gap-2">
                <Badge v-if="version" variant="outline">{{
                    version.statusLabel
                }}</Badge>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="saving || readOnly"
                    data-test="save-draft"
                    @click="save"
                >
                    <Save /> {{ version ? 'Save draft' : 'Save as draft' }}
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

        <p
            v-if="readOnly"
            class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
            This version is {{ version?.statusLabel.toLowerCase() }}, so it
            cannot be changed. Start a new version from the question page to
            make changes.
        </p>

        <InputError
            v-for="(message, key) in serverErrors"
            :key="key"
            :message="message"
        />

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="grid min-w-0 gap-6">
                <!-- Where the question belongs -->
                <section
                    class="grid gap-3 rounded-lg border p-4 sm:grid-cols-2"
                >
                    <div class="grid gap-1.5">
                        <Label for="course">Course *</Label>
                        <select
                            id="course"
                            v-model.number="draft.course_id"
                            class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            :disabled="readOnly"
                        >
                            <option
                                v-for="course in courses"
                                :key="course.id"
                                :value="course.id"
                            >
                                {{ course.code }} — {{ course.title }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="topic">Topic *</Label>
                        <select
                            id="topic"
                            v-model.number="draft.node_id"
                            class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            :disabled="readOnly"
                        >
                            <option :value="null">Choose…</option>
                            <option
                                v-for="node in topics"
                                :key="node.id"
                                :value="node.id"
                            >
                                {{ '— '.repeat(Math.max(0, node.depth - 1))
                                }}{{ node.name }}
                            </option>
                        </select>
                        <p
                            v-if="topics.length === 0"
                            class="text-muted-foreground text-xs"
                        >
                            This course has no topic that takes questions yet.
                            Add one in the CMS curriculum.
                        </p>
                        <InputError
                            v-for="(message, i) in checks.errors['node_id'] ??
                            []"
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
                        <p v-if="type" class="text-muted-foreground text-xs">
                            {{ type.description }}
                        </p>
                    </div>
                    <div class="flex gap-3">
                        <div class="grid flex-1 gap-1.5">
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
                                v-for="(message, i) in checks.errors['marks'] ??
                                []"
                                :key="i"
                                :message="message"
                            />
                        </div>
                        <div
                            v-if="type?.supportsNegativeMarks"
                            class="grid flex-1 gap-1.5"
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
                <section class="grid gap-4 rounded-lg border p-4">
                    <RichTextField
                        id="vignette"
                        v-model="draft.vignette"
                        label="Clinical scenario (optional)"
                        hint="The patient story. Basic formatting is kept; anything unsafe is removed when it is saved."
                        :rows="4"
                        :disabled="readOnly"
                    />
                    <RichTextField
                        id="stem"
                        v-model="draft.stem"
                        label="Question"
                        required
                        :rows="4"
                        :counter="{ min: limits.stemMin, max: limits.stemMax }"
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
                                    $event === '' ? null : String($event)
                            "
                        />
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

                <section class="grid gap-4 rounded-lg border p-4">
                    <RichTextField
                        id="explanation"
                        v-model="draft.explanation"
                        label="Explanation"
                        hint="Why the answer is right. Reviewers read this first."
                        :rows="3"
                        :disabled="readOnly"
                    />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="cognitive">Level of thinking</Label>
                            <select
                                id="cognitive"
                                v-model.number="draft.cognitive_level_id"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                :disabled="readOnly"
                            >
                                <option :value="null">Not chosen</option>
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
                            <Label for="difficulty">Expected difficulty</Label>
                            <select
                                id="difficulty"
                                v-model.number="draft.difficulty_level_id"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                :disabled="readOnly"
                            >
                                <option :value="null">Not chosen</option>
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

                <section
                    v-if="tags.length > 0"
                    class="grid gap-2 rounded-lg border p-4"
                >
                    <h3 class="font-medium">Tags</h3>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="tag in tags"
                            :key="tag.id"
                            class="flex cursor-pointer items-center gap-2 rounded-md border px-2 py-1 text-sm"
                        >
                            <input
                                type="checkbox"
                                :checked="draft.tag_ids.includes(tag.id)"
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
                </section>
            </div>

            <div class="grid content-start gap-4">
                <ChecksPanel :checks="checks" :checking="checking" />

                <div class="grid gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="justify-self-start"
                        @click="showAnswers = !showAnswers"
                    >
                        <Eye />
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
            </div>
        </div>
    </div>
</template>
