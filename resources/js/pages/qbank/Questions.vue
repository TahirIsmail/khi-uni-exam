<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CopyCheck,
    Download,
    Filter,
    History,
    Plus,
    Search,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index } from '@/routes/questions';
import type {
    CourseOption,
    CurriculumNode,
    ExamTypeOption,
    Paginated,
    QuestionListRow,
    QuestionTypeInfo,
    YearOption,
} from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Question bank', href: index() }] },
});

type Filters = {
    search: string;
    status: string;
    programme_id: number | null;
    year: string | null;
    exam_type_id: number | null;
    intake_id: number | null;
    used: string;
    used_from: string | null;
    used_to: string | null;
    course_id: number | null;
    node_id: number | null;
    discipline_id: number | null;
    type_id: number | null;
    cognitive_level_id: number | null;
    difficulty_level_id: number | null;
    tag_id: number | null;
    author_id: number | null;
    marks_min: number | null;
    marks_max: number | null;
    updated_from: string | null;
    updated_to: string | null;
    sort: string;
    mine: boolean;
    duplicates: boolean;
    archived: boolean;
};

const props = defineProps<{
    questions: Paginated<QuestionListRow>;
    filters: Filters;
    programmes: { id: number; name: string; code: string }[];
    years: YearOption[];
    examTypes: ExamTypeOption[];
    intakes: { id: number; name: string }[];
    courses: CourseOption[];
    disciplines: { id: number; code: string; name: string }[];
    tags: { id: number; name: string }[];
    types: QuestionTypeInfo[];
    cognitiveLevels: { id: number; name: string }[];
    difficultyLevels: { id: number; name: string }[];
    authors: { id: number; name: string }[];
    statuses: { key: string; label: string; count: number }[];
    canCreate: boolean;
    canExport: boolean;
    canEditOwn: boolean;
    canEditAny: boolean;
    canApprove: boolean;
    canDelete: boolean;
}>();

const search = ref(props.filters.search);
const showMore = ref(
    props.filters.year !== null ||
        props.filters.exam_type_id !== null ||
        props.filters.intake_id !== null ||
        props.filters.used !== '' ||
        props.filters.used_from !== null ||
        props.filters.used_to !== null ||
        props.filters.node_id !== null ||
        props.filters.discipline_id !== null ||
        props.filters.type_id !== null ||
        props.filters.cognitive_level_id !== null ||
        props.filters.difficulty_level_id !== null ||
        props.filters.tag_id !== null ||
        props.filters.author_id !== null ||
        props.filters.marks_min !== null ||
        props.filters.marks_max !== null ||
        props.filters.updated_from !== null ||
        props.filters.updated_to !== null,
);
const topics = ref<CurriculumNode[]>([]);

const yearsOfProgramme = computed(() =>
    props.filters.programme_id === null
        ? props.years
        : props.years.filter(
              (year) => year.programme_id === props.filters.programme_id,
          ),
);

// With no programme chosen, a year needs its programme's name to be told apart.
function yearLabel(year: YearOption): string {
    if (props.filters.programme_id !== null) {
        return year.name;
    }
    const programme = props.programmes.find(
        (row) => row.id === year.programme_id,
    );

    return programme ? `${programme.name} — ${year.name}` : year.name;
}

const selectedYear = computed(
    () => props.years.find((year) => year.id === props.filters.year) ?? null,
);

const coursesOfProgramme = computed(() =>
    props.courses.filter(
        (course) =>
            (props.filters.programme_id === null ||
                course.programme_id === props.filters.programme_id) &&
            (selectedYear.value === null ||
                (course.professional_id ===
                    selectedYear.value.professional_id &&
                    course.term_id === selectedYear.value.term_id)),
    ),
);

const activeFilterCount = computed(
    () =>
        Object.entries(props.filters).filter(
            ([key, value]) =>
                !['search', 'sort'].includes(key) &&
                value !== null &&
                value !== '' &&
                value !== false,
        ).length,
);

// Topics only make sense once a course is chosen.
watch(
    () => props.filters.course_id,
    async (courseId) => {
        topics.value = [];
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
            topics.value = (
                (await response.json()) as { nodes: CurriculumNode[] }
            ).nodes.filter((node) => node.allows_questions);
        }
    },
    { immediate: true },
);

function apply(changes: Partial<Record<keyof Filters, unknown>>): void {
    const current: Record<string, unknown> = {
        ...props.filters,
        search: search.value,
        ...changes,
    };

    const query = Object.fromEntries(
        Object.entries(current)
            .map(([key, value]) => [key, value === true ? 1 : value])
            .filter(
                ([, value]) =>
                    value !== null && value !== '' && value !== false,
            ),
    );

    router.get(index.url(), query, { preserveState: true, replace: true });
}

// The export takes exactly what the search is showing, so it carries the same filters.
const exportUrl = computed(() => {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(props.filters)) {
        if (value !== null && value !== '' && value !== false) {
            query.set(key, value === true ? '1' : String(value));
        }
    }
    const search = query.toString();

    return `/questions/export${search === '' ? '' : `?${search}`}`;
});

function clear(): void {
    search.value = '';
    router.get(index.url());
}

// Only a draft (or one sent back) can be edited, and only by somebody with the right to edit it.
function mayEdit(row: QuestionListRow): boolean {
    const editable =
        row.status === 'draft' || row.status === 'changes_requested';

    return editable && ((row.isMine && props.canEditOwn) || props.canEditAny);
}

// kmu-cms "Delete": an author deletes their own drafts, whoever may edit any question any question.
function mayDelete(row: QuestionListRow): boolean {
    const gone = ['archived', 'retired', 'superseded'].includes(row.status);
    const editable =
        row.status === 'draft' || row.status === 'changes_requested';

    return (
        props.canDelete &&
        !gone &&
        (props.canEditAny || (row.isMine && editable && props.canEditOwn))
    );
}

function deleteOne(row: QuestionListRow): void {
    if (
        !window.confirm(
            `Delete ${row.reference}? It leaves the QBank; it can still be found under Remove / Discard.`,
        )
    ) {
        return;
    }
    router.post(
        '/questions/bulk',
        { action: 'remove', version_ids: [row.versionId] },
        { preserveScroll: true },
    );
}

// The approver's own buttons are on the review screen, so "Open" takes them there.
function mayDecide(row: QuestionListRow): boolean {
    return (
        props.canApprove &&
        !row.isMine &&
        ['submitted', 'under_review'].includes(row.status)
    );
}

// ---- several questions at once -------------------------------------------------------------------
const canSelect = computed(() => props.canApprove || props.canDelete);
const selected = ref<number[]>([]);
const allSelected = computed(
    () =>
        props.questions.data.length > 0 &&
        props.questions.data.every((row) =>
            selected.value.includes(row.versionId),
        ),
);

function toggleAll(): void {
    selected.value = allSelected.value
        ? []
        : props.questions.data.map((row) => row.versionId);
}

function toggle(versionId: number): void {
    selected.value = selected.value.includes(versionId)
        ? selected.value.filter((id) => id !== versionId)
        : [...selected.value, versionId];
}

// A new page of results starts with nothing ticked.
watch(
    () => props.questions.data,
    () => (selected.value = []),
);

type BulkAction = 'accept' | 'retain' | 'revise' | 'remove';
const bulkLabels: Record<BulkAction, string> = {
    accept: 'Accept',
    retain: 'Retain in QBank',
    revise: 'Revise',
    remove: 'Remove / Discard',
};
const bulk = useForm<{
    action: BulkAction | null;
    version_ids: number[];
    reason: string;
}>({ action: null, version_ids: [], reason: '' });

function sendBulk(): void {
    bulk.version_ids = selected.value;
    bulk.post('/questions/bulk', {
        preserveScroll: true,
        onSuccess: () => {
            selected.value = [];
            bulk.reset();
        },
    });
}

/** Accept and Retain in QBank go after a confirmation; Revise asks why, Remove lets you say why. */
function startBulk(action: BulkAction): void {
    bulk.clearErrors();
    bulk.action = action;
    if (action === 'accept' || action === 'retain') {
        const count = selected.value.length;
        if (
            window.confirm(
                `${bulkLabels[action]}: store ${count} question${count === 1 ? '' : 's'} in the QBank?`,
            )
        ) {
            sendBulk();
        } else {
            bulk.action = null;
        }
    }
}

const statusStyles: Record<string, string> = {
    draft: 'secondary',
    changes_requested: 'destructive',
    approved: 'default',
    active: 'default',
    archived: 'outline',
};
</script>

<template>
    <Head title="Question bank" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Question bank"
                description="Questions of the campus you are working in. Search their text, or narrow by where they sit in the CMS academic structure."
            />
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="canExport"
                    as-child
                    variant="outline"
                    data-test="export-questions"
                >
                    <a :href="exportUrl"><Download /> Export these questions</a>
                </Button>
                <Button v-if="canCreate" as-child data-test="new-question">
                    <Link :href="create()"><Plus /> New question</Link>
                </Button>
            </div>
        </div>

        <form
            class="grid gap-3 rounded-xl border p-4 shadow-xs"
            @submit.prevent="apply({})"
        >
            <div class="flex flex-wrap items-end gap-2">
                <div class="grid flex-1 gap-1.5" style="min-width: 16rem">
                    <Label for="search">Search the question text</Label>
                    <Input
                        id="search"
                        v-model="search"
                        type="search"
                        maxlength="100"
                        placeholder="e.g. brachial plexus, or Q-2026-000123"
                    />
                </div>
                <Button type="submit" variant="outline"
                    ><Search /> Search</Button
                >
                <Button
                    type="button"
                    :variant="showMore ? 'default' : 'outline'"
                    @click="showMore = !showMore"
                >
                    <Filter /> More filters
                    <Badge
                        v-if="activeFilterCount > 0"
                        variant="secondary"
                        class="ml-1"
                        >{{ activeFilterCount }}</Badge
                    >
                </Button>
                <Button
                    v-if="activeFilterCount > 0 || filters.search !== ''"
                    type="button"
                    variant="ghost"
                    data-test="clear-filters"
                    @click="clear"
                >
                    <X /> Clear
                </Button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="text-muted-foreground text-xs">Status</span>
                <Button
                    type="button"
                    size="sm"
                    :variant="filters.status === '' ? 'default' : 'outline'"
                    data-status="all"
                    @click="apply({ status: 'all' })"
                    >All</Button
                >
                <Button
                    v-for="row in statuses"
                    :key="row.key"
                    type="button"
                    size="sm"
                    :variant="
                        filters.status === row.key ? 'default' : 'outline'
                    "
                    :data-status="row.key"
                    @click="apply({ status: row.key })"
                >
                    {{ row.label }}
                    <span class="text-muted-foreground ml-1 tabular-nums">{{
                        row.count
                    }}</span>
                </Button>
                <span class="mx-1 h-5 border-l" />
                <Button
                    type="button"
                    size="sm"
                    :variant="filters.mine ? 'default' : 'outline'"
                    @click="apply({ mine: !filters.mine })"
                    >Written by me</Button
                >
                <Button
                    type="button"
                    size="sm"
                    :variant="filters.duplicates ? 'default' : 'outline'"
                    data-test="duplicates-filter"
                    @click="apply({ duplicates: !filters.duplicates })"
                    ><CopyCheck /> Same text twice</Button
                >
            </div>

            <div
                v-if="showMore"
                class="grid gap-3 border-t pt-3 sm:grid-cols-2 lg:grid-cols-4"
            >
                <div class="grid gap-1.5">
                    <Label for="programme">Programme</Label>
                    <select
                        id="programme"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        :value="filters.programme_id ?? ''"
                        @change="
                            apply({
                                programme_id:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                                year: null,
                                course_id: null,
                                node_id: null,
                            })
                        "
                    >
                        <option value="">Any</option>
                        <option
                            v-for="row in programmes"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="year">Year / Semester</Label>
                    <select
                        id="year"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        :value="filters.year ?? ''"
                        data-test="filter-year"
                        @change="
                            apply({
                                year:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                                course_id: null,
                                node_id: null,
                            })
                        "
                    >
                        <option value="">Any</option>
                        <option
                            v-for="row in yearsOfProgramme"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ yearLabel(row) }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="intake">Academic Session</Label>
                    <select
                        id="intake"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        :value="filters.intake_id ?? ''"
                        data-test="filter-intake"
                        @change="
                            apply({
                                intake_id:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">Any</option>
                        <option
                            v-for="row in intakes"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="exam-type">Examination Type</Label>
                    <select
                        id="exam-type"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        :value="filters.exam_type_id ?? ''"
                        data-test="filter-exam-type"
                        @change="
                            apply({
                                exam_type_id:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">Any</option>
                        <option
                            v-for="row in examTypes"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="course">Module / Subject (Course ID)</Label>
                    <select
                        id="course"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        :value="filters.course_id ?? ''"
                        @change="
                            apply({
                                course_id:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                                node_id: null,
                            })
                        "
                    >
                        <option value="">Any</option>
                        <option
                            v-for="course in coursesOfProgramme"
                            :key="course.id"
                            :value="course.id"
                        >
                            {{ course.code }} — {{ course.title }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="topic">Topic (includes what is under it)</Label>
                    <select
                        id="topic"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm disabled:opacity-50"
                        :value="filters.node_id ?? ''"
                        :disabled="topics.length === 0"
                        @change="
                            apply({
                                node_id:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">Any</option>
                        <option
                            v-for="node in topics"
                            :key="node.id"
                            :value="node.id"
                        >
                            {{ '— '.repeat(Math.max(0, node.depth - 1))
                            }}{{ node.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="discipline">Discipline</Label>
                    <select
                        id="discipline"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        :value="filters.discipline_id ?? ''"
                        @change="
                            apply({
                                discipline_id:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">Any</option>
                        <option
                            v-for="row in disciplines"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="type">Type</Label>
                    <select
                        id="type"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        :value="filters.type_id ?? ''"
                        @change="
                            apply({
                                type_id:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">Any</option>
                        <option
                            v-for="row in types"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="cognitive">Cognitive level</Label>
                    <select
                        id="cognitive"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        :value="filters.cognitive_level_id ?? ''"
                        @change="
                            apply({
                                cognitive_level_id:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">Any</option>
                        <option
                            v-for="row in cognitiveLevels"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="difficulty">Difficulty level</Label>
                    <select
                        id="difficulty"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        :value="filters.difficulty_level_id ?? ''"
                        @change="
                            apply({
                                difficulty_level_id:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">Any</option>
                        <option
                            v-for="row in difficultyLevels"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="author">Author</Label>
                    <select
                        id="author"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        :value="filters.author_id ?? ''"
                        @change="
                            apply({
                                author_id:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">Anyone</option>
                        <option
                            v-for="row in authors"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="tag">Tag</Label>
                    <select
                        id="tag"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        :value="filters.tag_id ?? ''"
                        @change="
                            apply({
                                tag_id:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">Any</option>
                        <option
                            v-for="row in tags"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="marks-min">Marks from / to</Label>
                    <div class="flex items-center gap-2">
                        <Input
                            id="marks-min"
                            type="number"
                            step="0.5"
                            min="0"
                            class="h-9"
                            :model-value="filters.marks_min ?? ''"
                            @change="
                                apply({
                                    marks_min:
                                        ($event.target as HTMLInputElement)
                                            .value || null,
                                })
                            "
                        />
                        <Input
                            type="number"
                            step="0.5"
                            min="0"
                            class="h-9"
                            aria-label="Marks to"
                            :model-value="filters.marks_max ?? ''"
                            @change="
                                apply({
                                    marks_max:
                                        ($event.target as HTMLInputElement)
                                            .value || null,
                                })
                            "
                        />
                    </div>
                </div>
                <div class="grid gap-1.5">
                    <Label for="updated-from">Changed from / to</Label>
                    <div class="flex items-center gap-2">
                        <Input
                            id="updated-from"
                            type="date"
                            class="h-9"
                            :model-value="filters.updated_from ?? ''"
                            @change="
                                apply({
                                    updated_from:
                                        ($event.target as HTMLInputElement)
                                            .value || null,
                                })
                            "
                        />
                        <Input
                            type="date"
                            class="h-9"
                            aria-label="Changed to"
                            :model-value="filters.updated_to ?? ''"
                            @change="
                                apply({
                                    updated_to:
                                        ($event.target as HTMLInputElement)
                                            .value || null,
                                })
                            "
                        />
                    </div>
                </div>
                <div class="grid gap-1.5">
                    <Label for="used">Used in an examination</Label>
                    <select
                        id="used"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        :value="filters.used"
                        data-test="filter-used"
                        @change="
                            apply({
                                used: ($event.target as HTMLSelectElement)
                                    .value,
                            })
                        "
                    >
                        <option value="">Used or not</option>
                        <option value="used">Used before</option>
                        <option value="unused">Never used</option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="used-from">Previous examination date</Label>
                    <div class="flex items-center gap-2">
                        <Input
                            id="used-from"
                            type="date"
                            class="h-9"
                            :model-value="filters.used_from ?? ''"
                            @change="
                                apply({
                                    used_from:
                                        ($event.target as HTMLInputElement)
                                            .value || null,
                                })
                            "
                        />
                        <Input
                            type="date"
                            class="h-9"
                            aria-label="Previous examination to"
                            :model-value="filters.used_to ?? ''"
                            @change="
                                apply({
                                    used_to:
                                        ($event.target as HTMLInputElement)
                                            .value || null,
                                })
                            "
                        />
                    </div>
                </div>
                <div class="grid gap-1.5">
                    <Label for="sort">Order</Label>
                    <select
                        id="sort"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        :value="filters.sort"
                        @change="
                            apply({
                                sort: ($event.target as HTMLSelectElement)
                                    .value,
                            })
                        "
                    >
                        <option value="relevance">
                            Best match, then newest
                        </option>
                        <option value="updated">Newest change first</option>
                        <option value="oldest">Oldest change first</option>
                        <option value="marks">Most marks first</option>
                        <option value="reference">Reference</option>
                    </select>
                </div>
            </div>
        </form>

        <div
            v-if="selected.length > 0"
            class="bg-muted/40 grid gap-3 rounded-xl border p-3"
            data-test="bulk-bar"
        >
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium"
                    >{{ selected.length }} selected</span
                >
                <template v-if="canApprove">
                    <Button
                        size="sm"
                        :disabled="bulk.processing"
                        data-bulk="accept"
                        @click="startBulk('accept')"
                        >Accept</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="bulk.processing"
                        data-bulk="retain"
                        @click="startBulk('retain')"
                        >Retain in QBank</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="bulk.processing"
                        data-bulk="revise"
                        @click="startBulk('revise')"
                        >Revise</Button
                    >
                </template>
                <Button
                    size="sm"
                    variant="destructive"
                    :disabled="bulk.processing"
                    data-bulk="remove"
                    @click="startBulk('remove')"
                    >Remove / Discard</Button
                >
                <Button
                    size="sm"
                    variant="ghost"
                    @click="
                        selected = [];
                        bulk.reset();
                    "
                    ><X /> Clear</Button
                >
            </div>

            <div
                v-if="bulk.action === 'revise' || bulk.action === 'remove'"
                class="flex flex-wrap items-end gap-2"
            >
                <div class="grid flex-1 gap-1.5" style="min-width: 16rem">
                    <Label for="bulk-reason">{{
                        bulk.action === 'remove'
                            ? 'Why can these questions not be used? (optional)'
                            : 'What do the authors have to change?'
                    }}</Label>
                    <Input
                        id="bulk-reason"
                        v-model="bulk.reason"
                        maxlength="500"
                        data-test="bulk-reason"
                    />
                </div>
                <Button
                    :variant="
                        bulk.action === 'remove' ? 'destructive' : 'default'
                    "
                    :disabled="bulk.processing"
                    data-test="bulk-confirm"
                    @click="sendBulk"
                    >{{ bulkLabels[bulk.action] }} {{ selected.length }}</Button
                >
            </div>
            <p
                v-for="(message, field) in bulk.errors"
                :key="field"
                class="text-destructive text-sm"
            >
                {{ message }}
            </p>
        </div>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th v-if="canSelect" class="w-8 px-3 py-2">
                            <input
                                type="checkbox"
                                class="size-4"
                                :checked="allSelected"
                                :disabled="questions.data.length === 0"
                                title="Select all on this page"
                                aria-label="Select all on this page"
                                data-test="select-all"
                                @change="toggleAll"
                            />
                        </th>
                        <th class="px-3 py-2 font-medium">Reference</th>
                        <th class="px-3 py-2 font-medium">Question</th>
                        <th class="px-3 py-2 font-medium">Type</th>
                        <th class="px-3 py-2 font-medium">Course</th>
                        <th class="px-3 py-2 font-medium">Examination</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">Author</th>
                        <th class="px-3 py-2">
                            <span class="sr-only">Open</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in questions.data"
                        :key="row.id"
                        class="hover:bg-muted/40 border-t align-top"
                        :class="
                            selected.includes(row.versionId)
                                ? 'bg-muted/40'
                                : ''
                        "
                    >
                        <td v-if="canSelect" class="px-3 py-2">
                            <input
                                type="checkbox"
                                class="size-4"
                                :checked="selected.includes(row.versionId)"
                                :aria-label="`Select ${row.reference}`"
                                :data-select="row.versionId"
                                @change="toggle(row.versionId)"
                            />
                        </td>
                        <td
                            class="px-3 py-2 font-mono text-xs whitespace-nowrap"
                        >
                            <Link
                                :href="`/questions/${row.id}`"
                                class="underline-offset-4 hover:underline"
                            >
                                {{ row.reference }}
                            </Link>
                            <div class="text-muted-foreground">
                                v{{ row.versionNo }}
                            </div>
                        </td>
                        <td class="px-3 py-2">{{ row.summary }}</td>
                        <td class="px-3 py-2">{{ row.type }}</td>
                        <td class="px-3 py-2">{{ row.course }}</td>
                        <td class="px-3 py-2">{{ row.examType ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="
                                    (statusStyles[row.status] as 'default') ??
                                    'outline'
                                "
                                >{{ row.statusLabel }}</Badge
                            >
                        </td>
                        <td class="px-3 py-2">
                            {{ row.author }}
                            <Badge
                                v-if="row.isMine"
                                variant="outline"
                                class="ml-1"
                                >you</Badge
                            >
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <Button
                                as-child
                                size="sm"
                                variant="ghost"
                                title="History and versions"
                            >
                                <Link :href="`/questions/${row.id}`"
                                    ><History
                                /></Link>
                            </Button>
                            <Button
                                v-if="mayDecide(row)"
                                as-child
                                size="sm"
                                :data-decide="row.versionId"
                            >
                                <Link
                                    :href="`/questions/${row.id}/versions/${row.versionId}/review`"
                                    >Decide</Link
                                >
                            </Button>
                            <Button v-else as-child size="sm" variant="outline">
                                <Link
                                    :href="
                                        mayEdit(row)
                                            ? `/questions/${row.id}/versions/${row.versionId}/edit`
                                            : `/questions/${row.id}/versions/${row.versionId}`
                                    "
                                    >{{ mayEdit(row) ? 'Edit' : 'Open' }}</Link
                                >
                            </Button>
                            <Button
                                v-if="mayDelete(row)"
                                size="sm"
                                variant="ghost"
                                class="text-destructive"
                                title="Delete"
                                :data-delete="row.versionId"
                                @click="deleteOne(row)"
                                ><Trash2
                            /></Button>
                        </td>
                    </tr>
                    <tr v-if="questions.data.length === 0">
                        <td
                            :colspan="canSelect ? 9 : 8"
                            class="px-3 py-10 text-center"
                        >
                            <p class="font-medium">No questions found</p>
                            <p class="text-muted-foreground mt-1 text-sm">
                                {{
                                    activeFilterCount > 0 ||
                                    filters.search !== ''
                                        ? 'Nothing matches these filters yet.'
                                        : 'This campus has no questions yet. Write the first one.'
                                }}
                            </p>
                            <Button v-if="canCreate" as-child class="mt-4">
                                <Link :href="create()"
                                    ><Plus /> New question</Link
                                >
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="questions.last_page > 1"
            class="flex flex-wrap items-center gap-2 text-sm"
        >
            <span class="text-muted-foreground"
                >{{ questions.from }}–{{ questions.to }} of
                {{ questions.total }}</span
            >
            <template v-for="(link, i) in questions.links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md border px-3 py-1"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-accent'
                    "
                    >{{
                        link.label
                            .replace('&laquo;', '«')
                            .replace('&raquo;', '»')
                    }}</Link
                >
            </template>
        </nav>
    </div>
</template>
