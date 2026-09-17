<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CopyCheck, Filter, History, Plus, Search, X } from '@lucide/vue';
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
    Paginated,
    QuestionListRow,
    QuestionTypeInfo,
} from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Question bank', href: index() }] },
});

type Filters = {
    search: string;
    status: string;
    programme_id: number | null;
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
    courses: CourseOption[];
    disciplines: { id: number; code: string; name: string }[];
    tags: { id: number; name: string }[];
    types: QuestionTypeInfo[];
    cognitiveLevels: { id: number; name: string }[];
    difficultyLevels: { id: number; name: string }[];
    authors: { id: number; name: string }[];
    statuses: Record<string, number>;
    canCreate: boolean;
}>();

const search = ref(props.filters.search);
const showMore = ref(
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

const coursesOfProgramme = computed(() =>
    props.filters.programme_id === null
        ? props.courses
        : props.courses.filter(
              (course) => course.programme_id === props.filters.programme_id,
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

function clear(): void {
    search.value = '';
    router.get(index.url());
}

const statusStyles: Record<string, string> = {
    draft: 'secondary',
    changes_requested: 'destructive',
    active: 'default',
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
            <Button v-if="canCreate" as-child data-test="new-question">
                <Link :href="create()"><Plus /> New question</Link>
            </Button>
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
                        placeholder="e.g. chest pain, or Q-2026-000123"
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
                    @click="apply({ status: '' })"
                    >All</Button
                >
                <Button
                    v-for="(count, status) in statuses"
                    :key="status"
                    type="button"
                    size="sm"
                    :variant="filters.status === status ? 'default' : 'outline'"
                    @click="apply({ status })"
                >
                    {{ status.replace('_', ' ') }}
                    <span class="text-muted-foreground ml-1 tabular-nums">{{
                        count
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
                <Button
                    type="button"
                    size="sm"
                    :variant="filters.archived ? 'default' : 'outline'"
                    @click="apply({ archived: !filters.archived })"
                    >Archived</Button
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
                    <Label for="course">Course ID</Label>
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
                    <Label for="cognitive">Level of thinking</Label>
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
                    <Label for="difficulty">Expected difficulty</Label>
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

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Reference</th>
                        <th class="px-3 py-2 font-medium">Question</th>
                        <th class="px-3 py-2 font-medium">Type</th>
                        <th class="px-3 py-2 font-medium">Course</th>
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
                    >
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
                            <Button as-child size="sm" variant="outline">
                                <Link
                                    :href="
                                        row.status === 'draft' ||
                                        row.status === 'changes_requested'
                                            ? `/questions/${row.id}/versions/${row.versionId}/edit`
                                            : `/questions/${row.id}/versions/${row.versionId}`
                                    "
                                    >{{
                                        row.status === 'draft' ||
                                        row.status === 'changes_requested'
                                            ? 'Edit'
                                            : 'Open'
                                    }}</Link
                                >
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="questions.data.length === 0">
                        <td colspan="7" class="px-3 py-10 text-center">
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
