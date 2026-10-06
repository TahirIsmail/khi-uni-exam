<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    ChevronDown,
    ChevronRight,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { commit, destroy, index as importsIndex } from '@/routes/imports';
import { index as questionsIndex } from '@/routes/questions';
import type { ImportRowView, ImportSummary, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Question bank', href: questionsIndex() },
            { title: 'Import from a spreadsheet', href: importsIndex() },
        ],
    },
});

const props = defineProps<{
    import: ImportSummary;
    rows: Paginated<ImportRowView>;
    only: string;
    canCommit: boolean;
}>();

const open = ref<number | null>(null);
const working = ref(false);

function filter(only: string): void {
    router.get(
        `/questions/imports/${props.import.id}`,
        only === '' ? {} : { only },
        { preserveState: true, replace: true },
    );
}

function commitFile(): void {
    working.value = true;
    router.post(
        commit.url(props.import.id),
        {},
        { onFinish: () => (working.value = false) },
    );
}

function discard(): void {
    if (
        !window.confirm(
            'Discard this file? The rows that were checked will be forgotten and the file deleted. Nothing in the question bank changes.',
        )
    ) {
        return;
    }
    working.value = true;
    router.delete(destroy.url(props.import.id), {
        onFinish: () => (working.value = false),
    });
}

const rowStyles: Record<string, string> = {
    valid: 'secondary',
    invalid: 'destructive',
    duplicate: 'destructive',
    committed: 'default',
};
</script>

<template>
    <Head :title="`Import — ${props.import.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="props.import.name"
                :description="
                    props.import.status === 'committed'
                        ? `${props.import.rowsCommitted} of ${props.import.rowsTotal} rows were imported as drafts. They are now in the question bank, waiting for review.`
                        : `${props.import.rowsTotal} rows were read and checked. Nothing has been added to the question bank yet.`
                "
            />
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="props.import.committable && canCommit"
                    :disabled="props.import.rowsValid === 0 || working"
                    data-test="commit-import"
                    @click="commitFile"
                >
                    <Check />
                    Import
                    {{ props.import.rowsValid }}
                    {{
                        props.import.rowsValid === 1 ? 'question' : 'questions'
                    }}
                </Button>
                <Button
                    v-if="props.import.committable"
                    variant="outline"
                    :disabled="working"
                    data-test="discard-import"
                    @click="discard"
                >
                    <Trash2 /> Discard the file
                </Button>
                <Button
                    v-if="props.import.status === 'committed'"
                    as-child
                    variant="outline"
                >
                    <Link :href="questionsIndex()"
                        >See them in the question bank</Link
                    >
                </Button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="rounded-xl border p-4 shadow-xs">
                <p class="text-muted-foreground text-xs">Rows read</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ props.import.rowsTotal }}
                </p>
            </div>
            <div class="rounded-xl border p-4 shadow-xs">
                <p class="text-muted-foreground text-xs">Ready to import</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ props.import.rowsValid }}
                </p>
            </div>
            <div class="rounded-xl border p-4 shadow-xs">
                <p class="text-muted-foreground text-xs">With a problem</p>
                <p
                    class="text-2xl font-semibold tabular-nums"
                    :class="
                        props.import.rowsInvalid > 0 ? 'text-destructive' : ''
                    "
                >
                    {{ props.import.rowsInvalid }}
                </p>
            </div>
            <div class="rounded-xl border p-4 shadow-xs">
                <p class="text-muted-foreground text-xs">Imported</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ props.import.rowsCommitted }}
                </p>
            </div>
        </div>

        <p
            v-if="props.import.committable && props.import.rowsInvalid > 0"
            class="border-destructive/40 bg-destructive/5 text-destructive flex items-start gap-2 rounded-xl border p-4 text-sm"
            data-test="invalid-note"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            <span>
                {{ props.import.rowsInvalid }} rows cannot be imported as they
                are. Importing now takes only the
                {{ props.import.rowsValid }} good rows — correct the others in
                your spreadsheet and upload that file again.
            </span>
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-muted-foreground text-xs">Show</span>
            <Button
                size="sm"
                :variant="only === '' ? 'default' : 'outline'"
                @click="filter('')"
                >All rows</Button
            >
            <Button
                size="sm"
                :variant="only === 'valid' ? 'default' : 'outline'"
                @click="filter('valid')"
                >Ready</Button
            >
            <Button
                size="sm"
                :variant="only === 'invalid' ? 'default' : 'outline'"
                data-test="only-invalid"
                @click="filter('invalid')"
                >With a problem</Button
            >
            <Button
                size="sm"
                :variant="only === 'duplicate' ? 'default' : 'outline'"
                @click="filter('duplicate')"
                >Repeated</Button
            >
            <Button
                v-if="props.import.rowsCommitted > 0"
                size="sm"
                :variant="only === 'committed' ? 'default' : 'outline'"
                @click="filter('committed')"
                >Imported</Button
            >
        </div>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Line</th>
                        <th class="px-3 py-2 font-medium">Question</th>
                        <th class="px-3 py-2 font-medium">Type</th>
                        <th class="px-3 py-2 font-medium">Topic</th>
                        <th class="px-3 py-2 font-medium">Parts</th>
                        <th class="px-3 py-2 font-medium">Checked</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="row in rows.data" :key="row.id">
                        <tr
                            class="hover:bg-muted/40 cursor-pointer border-t align-top"
                            :data-row="row.rowNumber"
                            @click="open = open === row.id ? null : row.id"
                        >
                            <td
                                class="px-3 py-2 font-mono text-xs whitespace-nowrap"
                            >
                                <component
                                    :is="
                                        open === row.id
                                            ? ChevronDown
                                            : ChevronRight
                                    "
                                    class="inline size-3"
                                />
                                {{ row.rowNumber }}
                            </td>
                            <td class="px-3 py-2">
                                <span v-if="row.parsed">{{
                                    row.parsed.stem
                                }}</span>
                                <span v-else class="text-muted-foreground">{{
                                    Object.values(row.raw ?? {})
                                        .filter((value) => value !== '')
                                        .join(' · ')
                                        .slice(0, 120) || 'Empty row'
                                }}</span>
                            </td>
                            <td class="px-3 py-2">
                                {{ row.parsed?.type ?? '—' }}
                            </td>
                            <td class="px-3 py-2">
                                {{ row.parsed?.topic ?? '—' }}
                            </td>
                            <td
                                class="text-muted-foreground px-3 py-2 text-xs whitespace-nowrap"
                            >
                                <template v-if="row.parsed">
                                    {{ row.parsed.marks }}
                                    {{
                                        row.parsed.marks === 1
                                            ? 'mark'
                                            : 'marks'
                                    }}
                                    <span v-if="row.parsed.options > 0"
                                        >· {{ row.parsed.options }} options ({{
                                            row.parsed.correct
                                        }}
                                        correct)</span
                                    >
                                    <span v-if="row.parsed.items > 0"
                                        >·
                                        {{ row.parsed.items }} statements</span
                                    >
                                    <span v-if="row.parsed.answers > 0"
                                        >· {{ row.parsed.answers }} accepted
                                        answers</span
                                    >
                                </template>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <Badge
                                    :variant="
                                        (rowStyles[row.status] as 'default') ??
                                        'outline'
                                    "
                                    >{{
                                        row.status === 'duplicate'
                                            ? 'repeated'
                                            : row.status
                                    }}</Badge
                                >
                                <Badge
                                    v-if="row.warnings.length > 0"
                                    variant="outline"
                                    class="ml-1"
                                    >!</Badge
                                >
                            </td>
                        </tr>
                        <tr
                            v-if="
                                row.errors.length > 0 || row.warnings.length > 0
                            "
                            class="border-t-0"
                        >
                            <td />
                            <td colspan="5" class="px-3 pb-2">
                                <ul
                                    v-if="row.errors.length > 0"
                                    class="text-destructive list-disc pl-4 text-xs"
                                    :data-row-errors="row.rowNumber"
                                >
                                    <li
                                        v-for="(message, i) in row.errors"
                                        :key="i"
                                    >
                                        {{ message }}
                                    </li>
                                </ul>
                                <ul
                                    v-if="row.warnings.length > 0"
                                    class="text-muted-foreground list-disc pl-4 text-xs"
                                >
                                    <li
                                        v-for="(message, i) in row.warnings"
                                        :key="i"
                                    >
                                        {{ message }}
                                    </li>
                                </ul>
                            </td>
                        </tr>
                        <tr v-if="open === row.id" class="bg-muted/30 border-t">
                            <td />
                            <td colspan="5" class="px-3 py-3">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div v-if="row.questionId">
                                        <p
                                            class="text-muted-foreground text-xs"
                                        >
                                            Imported as
                                        </p>
                                        <Link
                                            :href="`/questions/${row.questionId}`"
                                            class="text-sm underline underline-offset-4"
                                            >the question this row became</Link
                                        >
                                    </div>
                                    <dl
                                        class="grid gap-1 text-xs sm:col-span-2 sm:grid-cols-2"
                                    >
                                        <div
                                            v-for="(value, column) in row.raw ??
                                            {}"
                                            :key="column"
                                            class="flex gap-2"
                                        >
                                            <dt
                                                class="text-muted-foreground w-28 shrink-0 font-mono"
                                            >
                                                {{ column }}
                                            </dt>
                                            <dd class="break-words">
                                                {{ value || '—' }}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="rows.data.length === 0">
                        <td colspan="6" class="px-3 py-10 text-center">
                            <p class="font-medium">No rows to show</p>
                            <p class="text-muted-foreground mt-1 text-sm">
                                Nothing in this file matches that filter.
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="rows.last_page > 1"
            class="flex flex-wrap items-center gap-2 text-sm"
        >
            <span class="text-muted-foreground"
                >{{ rows.from }}–{{ rows.to }} of {{ rows.total }}</span
            >
            <template v-for="(link, i) in rows.links" :key="i">
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
