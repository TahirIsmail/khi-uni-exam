<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index } from '@/routes/exams';
import type {
    BlueprintStatus,
    ExamChoices,
    ExaminationListRow,
    Paginated,
} from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Examinations', href: index() }] },
});

type Filters = {
    search: string;
    programme_id: number | null;
    year: string | null;
    exam_type_id: number | null;
    course_id: number | null;
    stage: '' | BlueprintStatus;
};

const props = defineProps<
    {
        examinations: Paginated<ExaminationListRow>;
        filters: Filters;
        stages: { key: BlueprintStatus; label: string; count: number }[];
        canCreate: boolean;
        /** Blueprints somebody else submitted that this person can approve. */
        waitingForMe: number;
    } & Pick<
        ExamChoices,
        'programmes' | 'years' | 'examTypes' | 'courses' | 'programmeCalendars'
    >
>();

const search = ref(props.filters.search);

const yearsOfProgramme = computed(() =>
    props.filters.programme_id === null
        ? props.years
        : props.years.filter(
              (year) => year.programme_id === props.filters.programme_id,
          ),
);

const examTypesOfProgramme = computed(() => {
    if (props.filters.programme_id === null) {
        return props.examTypes;
    }
    const calendar = props.programmeCalendars[props.filters.programme_id];

    return props.examTypes.filter((row) => row.calendar === calendar);
});

const selectedYear = computed(
    () => props.years.find((year) => year.id === props.filters.year) ?? null,
);

const coursesShown = computed(() =>
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

function yearLabel(year: (typeof props.years)[number]): string {
    if (props.filters.programme_id !== null) {
        return year.name;
    }
    const programme = props.programmes.find(
        (row) => row.id === year.programme_id,
    );

    return programme ? `${programme.name} — ${year.name}` : year.name;
}

const activeFilterCount = computed(
    () =>
        Object.entries(props.filters).filter(
            ([key, value]) =>
                key !== 'search' && value !== null && value !== '',
        ).length,
);

function apply(changes: Partial<Record<keyof Filters, unknown>>): void {
    const current: Record<string, unknown> = {
        ...props.filters,
        search: search.value,
        ...changes,
    };
    const query = Object.fromEntries(
        Object.entries(current).filter(
            ([, value]) => value !== null && value !== '',
        ),
    ) as Record<string, string | number>;

    router.get(index.url(), query, { preserveState: true, replace: true });
}

function clear(): void {
    search.value = '';
    router.get(index.url());
}

const stageStyle: Record<BlueprintStatus, 'secondary' | 'outline' | 'default'> =
    { draft: 'secondary', submitted: 'outline', approved: 'default' };

const number = (value: number): string =>
    new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(
        value,
    );
</script>

<template>
    <Head title="Examinations" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Examinations"
                description="Set an examination up, plan its blueprint, then build its paper from the question bank."
            />
            <Button v-if="canCreate" as-child data-test="new-exam">
                <Link :href="create()"><Plus /> New examination</Link>
            </Button>
        </div>

        <div
            v-if="waitingForMe > 0"
            class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
            data-test="waiting-for-me"
        >
            <span
                ><strong>{{ waitingForMe }}</strong>
                {{
                    waitingForMe === 1
                        ? 'blueprint is waiting'
                        : 'blueprints are waiting'
                }}
                for your approval.</span
            >
            <Button
                size="sm"
                variant="outline"
                data-test="show-waiting"
                @click="apply({ stage: 'submitted' })"
                >Show {{ waitingForMe === 1 ? 'it' : 'them' }}</Button
            >
        </div>

        <form
            class="grid gap-3 rounded-xl border p-4 shadow-xs"
            @submit.prevent="apply({})"
        >
            <div class="flex flex-wrap items-end gap-2">
                <div class="grid flex-1 gap-1.5" style="min-width: 16rem">
                    <Label for="search">Search</Label>
                    <Input
                        id="search"
                        v-model="search"
                        maxlength="100"
                        placeholder="Title or reference, e.g. EX-2026-0001"
                        data-test="exam-search"
                    />
                </div>
                <Button type="submit" variant="outline"
                    ><Search /> Search</Button
                >
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

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="grid min-w-0 gap-1.5">
                    <Label for="f-programme">Programme</Label>
                    <select
                        id="f-programme"
                        class="border-input bg-background h-9 w-full min-w-0 rounded-md border px-2 text-sm"
                        :value="filters.programme_id ?? ''"
                        data-test="filter-programme"
                        @change="
                            apply({
                                programme_id:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                                year: null,
                                exam_type_id: null,
                                course_id: null,
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
                <div class="grid min-w-0 gap-1.5">
                    <Label for="f-year">Year / Semester</Label>
                    <select
                        id="f-year"
                        class="border-input bg-background h-9 w-full min-w-0 rounded-md border px-2 text-sm"
                        :value="filters.year ?? ''"
                        data-test="filter-year"
                        @change="
                            apply({
                                year:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                                course_id: null,
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
                <div class="grid min-w-0 gap-1.5">
                    <Label for="f-exam-type">Examination</Label>
                    <select
                        id="f-exam-type"
                        class="border-input bg-background h-9 w-full min-w-0 rounded-md border px-2 text-sm"
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
                            v-for="row in examTypesOfProgramme"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.name }}
                        </option>
                    </select>
                </div>
                <div class="grid min-w-0 gap-1.5">
                    <Label for="f-course">Module / Subject (Course ID)</Label>
                    <select
                        id="f-course"
                        class="border-input bg-background h-9 w-full min-w-0 rounded-md border px-2 text-sm"
                        :value="filters.course_id ?? ''"
                        data-test="filter-course"
                        @change="
                            apply({
                                course_id:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">Any</option>
                        <option
                            v-for="row in coursesShown"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.code }} — {{ row.title }}
                        </option>
                    </select>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="text-muted-foreground text-xs">Blueprint</span>
                <Button
                    type="button"
                    size="sm"
                    :variant="filters.stage === '' ? 'default' : 'outline'"
                    @click="apply({ stage: '' })"
                    >All</Button
                >
                <Button
                    v-for="row in stages"
                    :key="row.key"
                    type="button"
                    size="sm"
                    :variant="filters.stage === row.key ? 'default' : 'outline'"
                    :data-stage="row.key"
                    @click="apply({ stage: row.key })"
                >
                    {{ row.label }}
                    <span class="text-muted-foreground ml-1 tabular-nums">{{
                        row.count
                    }}</span>
                </Button>
            </div>
        </form>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Reference</th>
                        <th class="px-3 py-2 font-medium">Examination</th>
                        <th class="px-3 py-2 font-medium">Course</th>
                        <th class="px-3 py-2 font-medium">Date</th>
                        <th class="px-3 py-2 font-medium">Marks</th>
                        <th class="px-3 py-2 font-medium">Blueprint</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in examinations.data"
                        :key="row.id"
                        class="hover:bg-muted/40 border-t align-top"
                        :data-exam="row.id"
                    >
                        <td
                            class="px-3 py-2 font-mono text-xs whitespace-nowrap"
                        >
                            <Link
                                :href="`/exams/${row.id}`"
                                class="underline-offset-4 hover:underline"
                                >{{ row.reference }}</Link
                            >
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                :href="`/exams/${row.id}`"
                                class="font-medium underline-offset-4 hover:underline"
                                >{{ row.title }}</Link
                            >
                            <div class="text-muted-foreground text-xs">
                                {{ row.programme }} · {{ row.year }} ·
                                {{ row.examType }}
                            </div>
                        </td>
                        <td class="px-3 py-2">{{ row.course }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ row.startsAt ?? 'Not fixed yet' }}
                            <div class="text-muted-foreground text-xs">
                                {{ row.durationMinutes }} minutes
                            </div>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap tabular-nums">
                            {{ number(row.totalMarks) }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge :variant="stageStyle[row.blueprintStatus]">{{
                                row.blueprintLabel
                            }}</Badge>
                            <div class="text-muted-foreground mt-1 text-xs">
                                {{ row.plannedQuestions }} questions planned,
                                {{ number(row.plannedMarks) }} marks
                            </div>
                        </td>
                    </tr>
                    <tr v-if="examinations.data.length === 0">
                        <td colspan="6" class="px-3 py-10 text-center">
                            <p class="font-medium">No examinations found</p>
                            <p class="text-muted-foreground mt-1 text-sm">
                                {{
                                    activeFilterCount > 0 ||
                                    filters.search !== ''
                                        ? 'Nothing matches these filters yet.'
                                        : 'Nothing has been set up in this campus yet.'
                                }}
                            </p>
                            <Button v-if="canCreate" as-child class="mt-4">
                                <Link :href="create()"
                                    ><Plus /> New examination</Link
                                >
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="examinations.last_page > 1"
            class="flex flex-wrap items-center gap-2 text-sm"
        >
            <span class="text-muted-foreground"
                >{{ examinations.from }}–{{ examinations.to }} of
                {{ examinations.total }}</span
            >
            <template v-for="(link, i) in examinations.links" :key="i">
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
