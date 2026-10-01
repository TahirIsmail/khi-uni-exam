<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Check, Plus, Save, Send, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import ExamJourney from '@/components/exam/ExamJourney.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/exams';
import type {
    BlueprintScreen,
    BlueprintTargetData,
    ExamAbilities,
    ExaminationDetail,
} from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Examinations', href: index() }] },
});

const props = defineProps<
    {
        examination: ExaminationDetail;
        can: ExamAbilities;
    } & BlueprintScreen
>();

type Row = {
    key: number;
    section: number | null;
    node_id: number | null;
    question_type_id: number | null;
    question_count: number | '';
    marks_each: number | '';
};

let nextKey = 1;
const newRow = (values: Partial<Row> = {}): Row => ({
    key: nextKey++,
    section: null,
    node_id: null,
    question_type_id: props.types[0]?.id ?? null,
    question_count: 1,
    marks_each: 1,
    ...values,
});

const editable = computed(() => props.forEditing);
const base = computed(() => `/exams/${props.examination.id}`);

const sections = ref<string[]>([...props.blueprint.sections]);
const rows = ref<Row[]>(props.blueprint.rows.map((row) => newRow(row)));

// A mix is a percentage for each level; a level left empty is not part of it.
type Mix = Record<number, number | ''>;
const mixOf = (targets: BlueprintTargetData[], levels: { id: number }[]): Mix =>
    Object.fromEntries(
        levels.map((level) => [
            level.id,
            targets.find((row) => row.level_id === level.id)?.percent ?? '',
        ]),
    );
const cognitive = ref<Mix>(
    mixOf(props.blueprint.cognitive, props.cognitiveLevels),
);
const difficulty = ref<Mix>(
    mixOf(props.blueprint.difficulty, props.difficultyLevels),
);

const mixes = computed(() => [
    {
        key: 'cognitive',
        title: 'Cognitive level mix',
        levels: props.cognitiveLevels,
        values: cognitive.value,
    },
    {
        key: 'difficulty',
        title: 'Difficulty mix',
        levels: props.difficultyLevels,
        values: difficulty.value,
    },
]);

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const saving = ref(false);

const targetsOf = (mix: Mix): BlueprintTargetData[] =>
    Object.entries(mix)
        .filter(([, percent]) => percent !== '' && percent !== null)
        .map(([level, percent]) => ({
            level_id: Number(level),
            percent: Number(percent),
        }));

const round2 = (value: number): number => Math.round(value * 100) / 100;
const sum = (mix: Mix): number =>
    round2(targetsOf(mix).reduce((total, row) => total + row.percent, 0));
const number = (value: number): string =>
    new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(
        value,
    );

const plannedQuestions = computed(() =>
    rows.value.reduce(
        (total, row) => total + (Number(row.question_count) || 0),
        0,
    ),
);
const plannedMarks = computed(() =>
    round2(
        rows.value.reduce(
            (total, row) =>
                total +
                (Number(row.question_count) || 0) *
                    (Number(row.marks_each) || 0),
            0,
        ),
    ),
);
const difference = computed(() =>
    round2(plannedMarks.value - props.examination.totalMarks),
);
const balanced = computed(() => Math.abs(difference.value) <= 0.005);
const progress = computed(() =>
    props.examination.totalMarks > 0
        ? Math.min(
              100,
              Math.round(
                  (plannedMarks.value / props.examination.totalMarks) * 100,
              ),
          )
        : 0,
);

const incomplete = computed(() =>
    rows.value.some(
        (row) =>
            row.node_id === null ||
            row.question_type_id === null ||
            !(Number(row.question_count) >= 1) ||
            !(Number(row.marks_each) > 0),
    ),
);

const availableFor = (row: Row): number =>
    row.node_id === null || row.question_type_id === null
        ? 0
        : (props.availability[row.node_id]?.[row.question_type_id] ?? 0);

const isShort = (row: Row): boolean =>
    row.node_id !== null &&
    availableFor(row) < (Number(row.question_count) || 0);

const duplicateKeys = computed(() => {
    const seen = new Set<string>();
    const duplicates = new Set<number>();
    for (const row of rows.value) {
        const id = [
            row.node_id,
            row.question_type_id,
            Number(row.marks_each).toFixed(2),
            row.section ?? '-',
        ].join('|');
        if (seen.has(id)) {
            duplicates.add(row.key);
        }
        seen.add(id);
    }

    return duplicates;
});

const topicLabel = (id: number | null): string =>
    props.topics.find((topic) => topic.id === id)?.label ?? '—';
const sectionLabel = (at: number | null): string =>
    at === null ? 'No section' : sections.value[at] || `Section ${at + 1}`;
const topicName = (id: number | null): string =>
    props.topics.find((topic) => topic.id === id)?.name ?? 'This topic';
const typeName = (id: number | null): string =>
    props.types.find((type) => type.id === id)?.name ?? 'Questions';

// The same findings the server makes when the blueprint is submitted, kept live as it is written.
const blockers = computed(() => {
    const found: string[] = [];
    if (rows.value.length === 0) {
        found.push(
            'Add at least one row: which topics the paper draws on, and how many questions of each type.',
        );
    } else if (!balanced.value) {
        found.push(
            `The rows add up to ${number(plannedMarks.value)} marks but the examination is out of ${number(props.examination.totalMarks)}: ${difference.value > 0 ? `take ${number(difference.value)} off` : `add ${number(-difference.value)}`}.`,
        );
    }
    for (const mix of mixes.value) {
        if (
            targetsOf(mix.values).length > 0 &&
            Math.abs(sum(mix.values) - 100) > 0.01
        ) {
            found.push(
                `The ${mix.key === 'cognitive' ? 'cognitive level' : 'difficulty level'} mix adds up to ${number(sum(mix.values))}%, not 100%.`,
            );
        }
    }
    if (incomplete.value) {
        found.push('Every row needs a topic, a type, and numbers above zero.');
    }
    if (duplicateKeys.value.size > 0) {
        found.push(
            'Two rows are the same topic, type, marks and section: raise the number of questions of one instead.',
        );
    }

    return found;
});

// What stops the blueprint being submitted: the above, and — unless the institution allows it — a bank
// that cannot give what the rows ask for.
const stoppers = computed(() =>
    props.limits.requireBank
        ? [...blockers.value, ...shortages.value]
        : blockers.value,
);

// A heading counts everything under it, so what is asked of a topic is what its own rows ask for and
// what the rows below it ask for: a paper can be built exactly when no topic is asked for more than
// the question bank holds for it.
const parents = computed(
    () => new Map(props.topics.map((topic) => [topic.id, topic.parentId])),
);
function isWithin(nodeId: number, ancestor: number): boolean {
    // 0 is the whole course: everything is within it.
    if (ancestor === 0) {
        return true;
    }
    let id: number | null = nodeId;
    for (let guard = 0; id !== null && guard < 20; guard++) {
        if (id === ancestor) {
            return true;
        }
        id = parents.value.get(id) ?? null;
    }

    return false;
}

const shortages = computed(() => {
    const seen = new Set<string>();
    const found: string[] = [];
    for (const row of rows.value) {
        if (row.node_id === null || row.question_type_id === null) {
            continue;
        }
        const key = `${row.node_id}-${row.question_type_id}`;
        if (seen.has(key)) {
            continue;
        }
        seen.add(key);

        const wanted = rows.value
            .filter(
                (other) =>
                    other.node_id !== null &&
                    other.question_type_id === row.question_type_id &&
                    isWithin(other.node_id, row.node_id as number),
            )
            .reduce(
                (total, other) => total + (Number(other.question_count) || 0),
                0,
            );
        const available =
            props.availability[row.node_id]?.[row.question_type_id] ?? 0;

        if (wanted > available) {
            found.push(
                `${topicName(row.node_id)}, ${typeName(row.question_type_id)}: ${wanted} wanted, ${available} in the question bank — ${wanted - available} more to write or import.`,
            );
        }
    }

    return found;
});

const dirty = ref(false);
const touch = (): void => {
    dirty.value = true;
};

function addRow(): void {
    if (rows.value.length < props.limits.maxRows) {
        rows.value.push(newRow());
        touch();
    }
}
function removeRow(key: number): void {
    rows.value = rows.value.filter((row) => row.key !== key);
    touch();
}
function addSection(): void {
    if (sections.value.length < props.limits.maxSections) {
        sections.value.push('');
        touch();
    }
}
function removeSection(index: number): void {
    sections.value.splice(index, 1);
    // Rows keep their section by its position, so those after it move up and the removed one's have none.
    for (const row of rows.value) {
        if (row.section === index) {
            row.section = null;
        } else if (row.section !== null && row.section > index) {
            row.section -= 1;
        }
    }
    touch();
}

function save(): void {
    // Sections are sent without the empty ones, so the rows' positions are worked out again.
    const kept = sections.value
        .map((name, index) => ({ name: name.trim(), index }))
        .filter((section) => section.name !== '');
    const position = new Map(kept.map((section, at) => [section.index, at]));

    router.put(
        `${base.value}/blueprint`,
        {
            sections: kept.map((section) => section.name),
            rows: rows.value.map((row) => ({
                section:
                    row.section === null
                        ? null
                        : (position.get(row.section) ?? null),
                node_id: row.node_id,
                question_type_id: row.question_type_id,
                question_count: Number(row.question_count),
                marks_each: Number(row.marks_each),
            })),
            cognitive: targetsOf(cognitive.value),
            difficulty: targetsOf(difficulty.value),
        },
        {
            preserveScroll: true,
            onStart: () => {
                saving.value = true;
            },
            onFinish: () => {
                saving.value = false;
            },
            onSuccess: () => {
                dirty.value = false;
            },
        },
    );
}

function submit(): void {
    router.post(`${base.value}/blueprint/submit`, {}, { preserveScroll: true });
}

const errorAt = (path: string): string | undefined => errors.value[path];

const field =
    'border-input bg-background h-9 w-full min-w-0 rounded-md border px-2 text-sm disabled:opacity-60';
</script>

<template>
    <Head :title="`${examination.reference} — blueprint`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`Blueprint — ${examination.title}`"
                :description="`${examination.reference} · ${examination.course} · ${examination.examType} · ${number(examination.totalMarks)} marks`"
            />
            <div class="flex flex-wrap items-center gap-2">
                <Button as-child variant="ghost">
                    <Link :href="base">Back to the examination</Link>
                </Button>
                <Button
                    v-if="editable"
                    :disabled="saving || incomplete || !dirty"
                    data-test="save-blueprint"
                    @click="save"
                >
                    <Save /> Save blueprint
                </Button>
                <Button
                    v-if="can.submit"
                    variant="outline"
                    :disabled="dirty || stoppers.length > 0"
                    :title="
                        dirty
                            ? 'Save the blueprint first'
                            : stoppers.length > 0
                              ? 'Fix what is marked first'
                              : ''
                    "
                    data-test="submit-blueprint"
                    @click="submit"
                >
                    <Send /> Submit for approval
                </Button>
            </div>
        </div>

        <ExamJourney :stage="blueprint.status" :exam-id="examination.id" />

        <p
            v-if="blueprint.status === 'draft' && blueprint.returnReason"
            class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
            data-test="returned"
        >
            <strong>Sent back:</strong> {{ blueprint.returnReason }}
        </p>
        <p
            v-if="!editable"
            class="text-muted-foreground rounded-lg border p-4 text-sm"
            data-test="read-only"
        >
            {{
                blueprint.status === 'draft'
                    ? 'You can read this blueprint but not change it.'
                    : `This blueprint is ${blueprint.statusLabel.toLowerCase()} and cannot be changed. The approving committee can send it back.`
            }}
        </p>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="grid content-start gap-6">
                <section
                    v-if="editable || sections.length > 0"
                    class="rounded-xl border shadow-xs"
                    data-test="sections"
                >
                    <header
                        class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
                    >
                        <div>
                            <h2 class="font-medium">Sections</h2>
                            <p class="text-muted-foreground text-xs">
                                Optional. Split the paper into parts, such as
                                Section A multiple choice and Section B short
                                answer.
                            </p>
                        </div>
                        <Button
                            v-if="editable"
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="sections.length >= limits.maxSections"
                            data-test="add-section"
                            @click="addSection"
                        >
                            <Plus /> Add section
                        </Button>
                    </header>
                    <div class="grid gap-2 p-4">
                        <p
                            v-if="sections.length === 0"
                            class="text-muted-foreground text-sm"
                        >
                            No sections: the paper is one part.
                        </p>
                        <div
                            v-for="(_, at) in sections"
                            :key="at"
                            class="flex items-center gap-2"
                        >
                            <span
                                class="text-muted-foreground w-20 shrink-0 text-xs"
                                >Section {{ at + 1 }}</span
                            >
                            <span
                                v-if="!editable"
                                class="font-medium"
                                :data-section-text="at"
                                >{{ sections[at] }}</span
                            >
                            <Input
                                v-else
                                v-model="sections[at]"
                                maxlength="100"
                                placeholder="e.g. Section A — Single best answer"
                                :data-section="at"
                                @input="touch"
                            />
                            <Button
                                v-if="editable"
                                type="button"
                                size="icon"
                                variant="ghost"
                                title="Remove this section"
                                @click="removeSection(at)"
                            >
                                <Trash2 />
                            </Button>
                        </div>
                        <p
                            v-if="errorAt('sections')"
                            class="text-destructive text-sm"
                        >
                            {{ errorAt('sections') }}
                        </p>
                    </div>
                </section>

                <section class="rounded-xl border shadow-xs" data-test="rows">
                    <header
                        class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
                    >
                        <div>
                            <h2 class="font-medium">What the paper asks for</h2>
                            <p class="text-muted-foreground text-xs">
                                Each row: how many questions of one type, from
                                one topic (or a whole heading), and what each is
                                worth.
                            </p>
                        </div>
                        <Button
                            v-if="editable"
                            type="button"
                            size="sm"
                            :disabled="rows.length >= limits.maxRows"
                            data-test="add-row"
                            @click="addRow"
                        >
                            <Plus /> Add row
                        </Button>
                    </header>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[40rem] text-left text-sm">
                            <thead class="bg-muted/50 text-muted-foreground">
                                <tr>
                                    <th
                                        class="min-w-[16rem] px-3 py-2 font-medium"
                                    >
                                        Topic
                                    </th>
                                    <th
                                        class="min-w-[10rem] px-3 py-2 font-medium"
                                    >
                                        Type of question
                                    </th>
                                    <th class="w-24 px-3 py-2 font-medium">
                                        Questions
                                    </th>
                                    <th class="w-24 px-3 py-2 font-medium">
                                        Marks each
                                    </th>
                                    <th class="w-20 px-3 py-2 font-medium">
                                        Marks
                                    </th>
                                    <th class="w-28 px-3 py-2 font-medium">
                                        In the QBank
                                    </th>
                                    <th class="w-10 px-3 py-2">
                                        <span class="sr-only">Remove</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(row, at) in rows"
                                    :key="row.key"
                                    class="border-t align-top"
                                    :data-row="at"
                                >
                                    <td class="px-3 py-2">
                                        <span
                                            v-if="!editable"
                                            class="block py-2 leading-snug font-medium"
                                            data-test="row-topic-text"
                                            >{{ topicLabel(row.node_id) }}</span
                                        >
                                        <select
                                            v-else
                                            v-model.number="row.node_id"
                                            :class="field"
                                            data-test="row-topic"
                                            @change="touch"
                                        >
                                            <option :value="null">
                                                Choose…
                                            </option>
                                            <option
                                                v-for="topic in topics"
                                                :key="topic.id"
                                                :value="topic.id"
                                            >
                                                {{ topic.label }}
                                            </option>
                                        </select>
                                        <p
                                            v-if="
                                                editable &&
                                                errorAt(`rows.${at}.node_id`)
                                            "
                                            class="text-destructive mt-1 text-xs"
                                        >
                                            {{ errorAt(`rows.${at}.node_id`) }}
                                        </p>
                                        <p
                                            v-else-if="
                                                editable &&
                                                duplicateKeys.has(row.key)
                                            "
                                            class="text-destructive mt-1 text-xs"
                                        >
                                            Already a row: raise its questions
                                            instead.
                                        </p>
                                        <div
                                            v-if="sections.length > 0"
                                            class="mt-2 flex items-center gap-2"
                                        >
                                            <span
                                                class="text-muted-foreground shrink-0 text-xs"
                                                >Section</span
                                            >
                                            <span
                                                v-if="!editable"
                                                data-test="row-section-text"
                                                >{{
                                                    sectionLabel(row.section)
                                                }}</span
                                            >
                                            <select
                                                v-else
                                                v-model="row.section"
                                                :class="field"
                                                data-test="row-section"
                                                @change="touch"
                                            >
                                                <option :value="null">
                                                    None
                                                </option>
                                                <option
                                                    v-for="(
                                                        name, sectionAt
                                                    ) in sections"
                                                    :key="sectionAt"
                                                    :value="sectionAt"
                                                >
                                                    {{
                                                        name ||
                                                        `Section ${sectionAt + 1}`
                                                    }}
                                                </option>
                                            </select>
                                        </div>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span
                                            v-if="!editable"
                                            class="block py-2 leading-snug"
                                            data-test="row-type-text"
                                            >{{
                                                typeName(row.question_type_id)
                                            }}</span
                                        >
                                        <select
                                            v-else
                                            v-model.number="
                                                row.question_type_id
                                            "
                                            :class="field"
                                            data-test="row-type"
                                            @change="touch"
                                        >
                                            <option
                                                v-for="type in types"
                                                :key="type.id"
                                                :value="type.id"
                                            >
                                                {{ type.name }}
                                            </option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span
                                            v-if="!editable"
                                            class="block leading-9 tabular-nums"
                                            data-test="row-count-text"
                                            >{{ row.question_count }}</span
                                        >
                                        <Input
                                            v-else
                                            v-model.number="row.question_count"
                                            type="number"
                                            min="1"
                                            :max="limits.maxCount"
                                            data-test="row-count"
                                            @input="touch"
                                        />
                                    </td>
                                    <td class="px-3 py-2">
                                        <span
                                            v-if="!editable"
                                            class="block leading-9 tabular-nums"
                                            data-test="row-marks-text"
                                            >{{ row.marks_each }}</span
                                        >
                                        <Input
                                            v-else
                                            v-model.number="row.marks_each"
                                            type="number"
                                            min="0.25"
                                            :max="limits.marksMax"
                                            step="0.25"
                                            data-test="row-marks"
                                            @input="touch"
                                        />
                                    </td>
                                    <td
                                        class="px-3 py-2 leading-9 tabular-nums"
                                        data-test="row-total"
                                    >
                                        {{
                                            number(
                                                (Number(row.question_count) ||
                                                    0) *
                                                    (Number(row.marks_each) ||
                                                        0),
                                            )
                                        }}
                                    </td>
                                    <td
                                        class="px-3 py-2 leading-9 tabular-nums"
                                        :class="
                                            isShort(row)
                                                ? 'text-destructive font-medium'
                                                : 'text-muted-foreground'
                                        "
                                        data-test="row-available"
                                    >
                                        {{
                                            row.node_id === null
                                                ? '—'
                                                : availableFor(row)
                                        }}
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <Button
                                            v-if="editable"
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            title="Remove this row"
                                            data-test="remove-row"
                                            @click="removeRow(row.key)"
                                        >
                                            <Trash2 />
                                        </Button>
                                    </td>
                                </tr>
                                <tr v-if="rows.length === 0">
                                    <td
                                        colspan="7"
                                        class="text-muted-foreground px-3 py-8 text-center"
                                    >
                                        No rows yet.
                                        {{
                                            editable
                                                ? 'Add the first one: choose a topic, a type of question, and how many.'
                                                : ''
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot v-if="rows.length > 0" class="border-t">
                                <tr class="bg-muted/30 font-medium">
                                    <td colspan="2" class="px-3 py-2">Total</td>
                                    <td
                                        class="px-3 py-2 tabular-nums"
                                        data-test="total-questions"
                                    >
                                        {{ plannedQuestions }}
                                    </td>
                                    <td class="px-3 py-2" />
                                    <td
                                        class="px-3 py-2 tabular-nums"
                                        data-test="total-marks"
                                    >
                                        {{ number(plannedMarks) }}
                                    </td>
                                    <td colspan="2" />
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </section>
            </div>

            <aside class="grid content-start gap-6 xl:sticky xl:top-4">
                <section class="rounded-xl border shadow-xs" data-test="totals">
                    <header class="border-b px-4 py-3">
                        <h2 class="font-medium">Does it add up?</h2>
                    </header>
                    <div class="grid gap-3 p-4 text-sm">
                        <div class="flex items-baseline justify-between">
                            <span class="text-muted-foreground"
                                >Planned marks</span
                            >
                            <span class="tabular-nums" data-test="planned"
                                >{{ number(plannedMarks) }} of
                                {{ number(examination.totalMarks) }}</span
                            >
                        </div>
                        <div class="bg-muted h-2 overflow-hidden rounded-full">
                            <div
                                class="h-full rounded-full"
                                :class="
                                    balanced ? 'bg-green-600' : 'bg-primary'
                                "
                                :style="{ width: `${progress}%` }"
                            />
                        </div>
                        <p class="text-muted-foreground text-xs">
                            {{ plannedQuestions }} questions in
                            {{ rows.length }}
                            {{ rows.length === 1 ? 'row' : 'rows' }}
                        </p>

                        <ul
                            v-if="stoppers.length > 0"
                            class="grid gap-1 rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                            data-test="blockers"
                        >
                            <li
                                v-for="text in stoppers"
                                :key="text"
                                class="flex gap-2"
                            >
                                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                                {{ text }}
                            </li>
                        </ul>
                        <p
                            v-else
                            class="flex items-center gap-2 text-green-700 dark:text-green-400"
                            data-test="sound"
                        >
                            <Check class="size-4" /> Ready to submit.
                        </p>

                        <ul
                            v-if="shortages.length > 0 && !limits.requireBank"
                            class="text-muted-foreground grid gap-1 text-xs"
                            data-test="shortages"
                        >
                            <li v-for="text in shortages" :key="text">
                                • {{ text }}
                            </li>
                        </ul>
                        <p
                            v-if="errorAt('blueprint')"
                            class="text-destructive text-sm"
                        >
                            {{ errorAt('blueprint') }}
                        </p>
                    </div>
                </section>

                <section
                    v-for="mix in mixes"
                    :key="mix.key"
                    class="rounded-xl border shadow-xs"
                    :data-test="`mix-${mix.key}`"
                >
                    <header class="border-b px-4 py-3">
                        <h2 class="font-medium">{{ mix.title }}</h2>
                        <p class="text-muted-foreground text-xs">
                            Optional. The share of the paper at each level; it
                            has to add up to 100%.
                        </p>
                    </header>
                    <div class="grid gap-2 p-4 text-sm">
                        <div
                            v-for="level in mix.levels"
                            :key="level.id"
                            class="flex items-center justify-between gap-3"
                        >
                            <label :for="`${mix.key}-${level.id}`">{{
                                level.name
                            }}</label>
                            <div class="flex items-center gap-1">
                                <Input
                                    v-if="editable"
                                    :id="`${mix.key}-${level.id}`"
                                    v-model.number="mix.values[level.id]"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="5"
                                    class="w-20"
                                    :data-mix="`${mix.key}-${level.id}`"
                                    @input="touch"
                                />
                                <span
                                    v-if="editable"
                                    class="text-muted-foreground"
                                    >%</span
                                >
                                <span
                                    v-else
                                    class="tabular-nums"
                                    :data-mix-text="`${mix.key}-${level.id}`"
                                    >{{
                                        mix.values[level.id] === ''
                                            ? '—'
                                            : `${mix.values[level.id]}%`
                                    }}</span
                                >
                            </div>
                        </div>
                        <p
                            class="border-t pt-2 text-xs"
                            :class="
                                targetsOf(mix.values).length === 0
                                    ? 'text-muted-foreground'
                                    : Math.abs(sum(mix.values) - 100) <= 0.01
                                      ? 'text-green-700 dark:text-green-400'
                                      : 'text-destructive'
                            "
                            :data-test="`mix-sum-${mix.key}`"
                        >
                            {{
                                targetsOf(mix.values).length === 0
                                    ? 'Not set'
                                    : `Adds up to ${number(sum(mix.values))}%`
                            }}
                        </p>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</template>
