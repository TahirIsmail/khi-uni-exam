<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CopyCheck, FilePlus2, GitCompare, History } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/questions';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Question bank', href: index() }] },
});

type VersionRow = {
    id: number;
    versionNo: number;
    status: string;
    statusLabel: string;
    type: string;
    marks: number;
    author: string;
    isActive: boolean;
    summary: string;
    createdAt: string | null;
    submittedAt: string | null;
    approvedAt: string | null;
    activatedAt: string | null;
};

const props = defineProps<{
    question: {
        id: number;
        reference: string;
        course: string;
        activeVersionId: number | null;
        latestVersionNo: number;
        isArchived: boolean;
        archiveReason: string | null;
        timesUsed: number;
    };
    versions: VersionRow[];
    timeline: {
        at: string | null;
        versionNo: number;
        what: string;
        by: string;
        note: string | null;
        status: string;
    }[];
    usage: {
        timesUsed: number;
        candidatesTotal: number;
        lastUsedAt: string | null;
        exams: {
            exam: string;
            usedOn: string | null;
            candidates: number | null;
            difficultyIndex: number | null;
            discriminationIndex: number | null;
        }[];
    };
    duplicates: {
        id: number;
        reference: string;
        status: string;
        summary: string;
    }[];
}>();

const compareFrom = ref<number | null>(props.versions.at(-1)?.id ?? null);
const compareTo = ref<number | null>(props.versions[0]?.id ?? null);

const canCompare = computed(
    () =>
        props.versions.length > 1 &&
        compareFrom.value !== null &&
        compareTo.value !== null &&
        compareFrom.value !== compareTo.value,
);

const editable = (status: string): boolean =>
    status === 'draft' || status === 'changes_requested';

function when(value: string | null): string {
    return value === null ? '—' : new Date(value).toLocaleString();
}

function compare(): void {
    router.get(`/questions/${props.question.id}/diff`, {
        from: compareFrom.value,
        to: compareTo.value,
    });
}

function startNewVersion(): void {
    router.post(`/questions/${props.question.id}/versions`);
}
</script>

<template>
    <Head :title="question.reference" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="question.reference"
                :description="`${question.course} · ${versions.length} version${versions.length === 1 ? '' : 's'}${question.timesUsed > 0 ? ` · used in ${question.timesUsed} exam${question.timesUsed === 1 ? '' : 's'}` : ''}`"
            />
            <div class="flex flex-wrap items-center gap-2">
                <Badge v-if="question.isArchived" variant="destructive"
                    >Archived</Badge
                >
                <Button
                    v-if="
                        !versions.some((version) => editable(version.status)) &&
                        !question.isArchived
                    "
                    type="button"
                    variant="outline"
                    data-test="new-version"
                    @click="startNewVersion"
                >
                    <FilePlus2 /> New version
                </Button>
            </div>
        </div>

        <p
            v-if="question.isArchived && question.archiveReason"
            class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
            Archived: {{ question.archiveReason }}
        </p>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="grid gap-6">
                <!-- Versions -->
                <section class="rounded-xl border shadow-xs">
                    <header
                        class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
                    >
                        <div class="flex items-center gap-2">
                            <History class="text-muted-foreground size-4" />
                            <h2 class="font-medium">Versions</h2>
                        </div>
                        <div
                            v-if="versions.length > 1"
                            class="flex flex-wrap items-center gap-2 text-sm"
                        >
                            <select
                                v-model.number="compareFrom"
                                class="border-input bg-background h-8 rounded-md border px-2 text-sm"
                                aria-label="Compare from"
                                data-test="compare-from"
                            >
                                <option
                                    v-for="version in versions"
                                    :key="version.id"
                                    :value="version.id"
                                >
                                    v{{ version.versionNo }}
                                </option>
                            </select>
                            <span class="text-muted-foreground">with</span>
                            <select
                                v-model.number="compareTo"
                                class="border-input bg-background h-8 rounded-md border px-2 text-sm"
                                aria-label="Compare with"
                                data-test="compare-to"
                            >
                                <option
                                    v-for="version in versions"
                                    :key="version.id"
                                    :value="version.id"
                                >
                                    v{{ version.versionNo }}
                                </option>
                            </select>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="!canCompare"
                                data-test="compare"
                                @click="compare"
                            >
                                <GitCompare /> Compare
                            </Button>
                        </div>
                    </header>
                    <ul class="divide-y">
                        <li
                            v-for="version in versions"
                            :key="version.id"
                            class="grid gap-2 px-4 py-3 sm:grid-cols-[6rem_1fr_auto] sm:items-start"
                            :data-version="version.versionNo"
                        >
                            <div class="flex items-center gap-2">
                                <span class="font-medium"
                                    >v{{ version.versionNo }}</span
                                >
                                <Badge v-if="version.isActive" variant="default"
                                    >in use</Badge
                                >
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm">
                                    {{ version.summary }}
                                </p>
                                <p class="text-muted-foreground mt-1 text-xs">
                                    {{ version.type }} ·
                                    {{ version.marks }} mark{{
                                        version.marks === 1 ? '' : 's'
                                    }}
                                    · {{ version.author }} · written
                                    {{ when(version.createdAt) }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Badge variant="secondary">{{
                                    version.statusLabel
                                }}</Badge>
                                <Button as-child size="sm" variant="outline">
                                    <Link
                                        :href="
                                            editable(version.status)
                                                ? `/questions/${question.id}/versions/${version.id}/edit`
                                                : `/questions/${question.id}/versions/${version.id}`
                                        "
                                        >{{
                                            editable(version.status)
                                                ? 'Edit'
                                                : 'Open'
                                        }}</Link
                                    >
                                </Button>
                            </div>
                        </li>
                    </ul>
                </section>

                <!-- Where it has been used -->
                <section class="rounded-xl border shadow-xs" data-test="usage">
                    <header
                        class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
                    >
                        <h2 class="font-medium">Use in examinations</h2>
                        <span class="text-muted-foreground text-sm">
                            Used {{ usage.timesUsed }}
                            {{ usage.timesUsed === 1 ? 'time' : 'times' }}
                            <template v-if="usage.candidatesTotal > 0"
                                >· {{ usage.candidatesTotal }} students
                                attempted it</template
                            >
                        </span>
                    </header>
                    <div v-if="usage.exams.length > 0" class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left">
                                <tr>
                                    <th class="px-4 py-2 font-medium">
                                        Examination
                                    </th>
                                    <th class="px-4 py-2 font-medium">Date</th>
                                    <th class="px-4 py-2 font-medium">
                                        Students
                                    </th>
                                    <th class="px-4 py-2 font-medium">
                                        Difficulty index
                                    </th>
                                    <th class="px-4 py-2 font-medium">
                                        Discrimination index
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(row, i) in usage.exams"
                                    :key="i"
                                    class="border-t"
                                >
                                    <td class="px-4 py-2">{{ row.exam }}</td>
                                    <td class="px-4 py-2">
                                        {{ row.usedOn ?? '—' }}
                                    </td>
                                    <td class="px-4 py-2 tabular-nums">
                                        {{ row.candidates ?? '—' }}
                                    </td>
                                    <td class="px-4 py-2 tabular-nums">
                                        {{ row.difficultyIndex ?? '—' }}
                                    </td>
                                    <td class="px-4 py-2 tabular-nums">
                                        {{ row.discriminationIndex ?? '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="text-muted-foreground px-4 py-6 text-sm">
                        Not used in any examination yet. When it is, each
                        examination appears here with its date, how many
                        students attempted the question, and how it performed.
                    </p>
                </section>

                <!-- Timeline -->
                <section class="rounded-xl border shadow-xs">
                    <header class="flex items-center gap-2 border-b px-4 py-3">
                        <History class="text-muted-foreground size-4" />
                        <h2 class="font-medium">What happened</h2>
                    </header>
                    <ol class="divide-y" data-test="timeline">
                        <li
                            v-for="(entry, index) in timeline"
                            :key="index"
                            class="grid gap-1 px-4 py-3 sm:grid-cols-[11rem_1fr]"
                        >
                            <span class="text-muted-foreground text-xs">{{
                                when(entry.at)
                            }}</span>
                            <div>
                                <p class="text-sm">
                                    {{ entry.what }}
                                    <span class="text-muted-foreground"
                                        >· v{{ entry.versionNo }} ·
                                        {{ entry.by }}</span
                                    >
                                </p>
                                <p
                                    v-if="entry.note"
                                    class="text-muted-foreground mt-0.5 text-xs"
                                >
                                    “{{ entry.note }}”
                                </p>
                            </div>
                        </li>
                    </ol>
                </section>
            </div>

            <div class="grid content-start gap-4">
                <section
                    v-if="duplicates.length > 0"
                    class="rounded-xl border shadow-xs"
                >
                    <header class="flex items-center gap-2 border-b px-4 py-3">
                        <CopyCheck class="text-muted-foreground size-4" />
                        <h2 class="font-medium">Same text elsewhere</h2>
                    </header>
                    <ul class="divide-y text-sm" data-test="duplicates">
                        <li
                            v-for="row in duplicates"
                            :key="row.id"
                            class="grid gap-1 px-4 py-3"
                        >
                            <Link
                                :href="`/questions/${row.id}`"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ row.reference }}
                            </Link>
                            <span class="text-muted-foreground text-xs">{{
                                row.status
                            }}</span>
                            <p class="text-muted-foreground text-xs">
                                {{ row.summary }}
                            </p>
                        </li>
                    </ul>
                </section>
                <p v-else class="text-muted-foreground text-sm">
                    No other question in this campus has the same text.
                </p>
            </div>
        </div>
    </div>
</template>
