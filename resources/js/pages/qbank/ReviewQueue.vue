<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, ClipboardCheck } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index as approvals } from '@/routes/approvals';
import { index as questions } from '@/routes/questions';
import { index as reviews } from '@/routes/reviews';
import type { Paginated, ReviewAssignmentRow } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Question bank', href: questions() },
            { title: 'My reviews', href: reviews() },
        ],
    },
});

const props = defineProps<{
    assignments: Paginated<ReviewAssignmentRow>;
    show: string;
    canApprove: boolean;
}>();

function filter(show: string): void {
    router.get(reviews.url(), show === 'open' ? {} : { show }, {
        preserveState: true,
        replace: true,
    });
}

function due(row: ReviewAssignmentRow): string {
    if (row.dueAt === null) {
        return '—';
    }
    const date = new Date(row.dueAt);
    const days = Math.round((date.getTime() - Date.now()) / 86400000);
    if (row.status !== 'open') {
        return date.toLocaleDateString();
    }
    if (days < 0) {
        return `${-days} day${days === -1 ? '' : 's'} late`;
    }
    return days === 0 ? 'today' : `in ${days} day${days === 1 ? '' : 's'}`;
}
</script>

<template>
    <Head title="My reviews" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="My reviews"
                description="Questions your department has asked you to review. Read each one as a candidate would see it, work through the item-writing checklist, and either review it or send it back to its author."
            />
            <Button v-if="canApprove" as-child variant="outline">
                <Link :href="approvals()">Approvals</Link>
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                size="sm"
                :variant="show === 'open' ? 'default' : 'outline'"
                data-test="show-open"
                @click="filter('open')"
                >To review</Button
            >
            <Button
                size="sm"
                :variant="show === 'done' ? 'default' : 'outline'"
                @click="filter('done')"
                >Reviewed by me</Button
            >
            <Button
                size="sm"
                :variant="show === 'all' ? 'default' : 'outline'"
                @click="filter('all')"
                >All</Button
            >
        </div>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Reference</th>
                        <th class="px-3 py-2 font-medium">Question</th>
                        <th class="px-3 py-2 font-medium">Type</th>
                        <th class="px-3 py-2 font-medium">Course</th>
                        <th class="px-3 py-2 font-medium">Due</th>
                        <th class="px-3 py-2">
                            <span class="sr-only">Review</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in assignments.data"
                        :key="row.id"
                        class="hover:bg-muted/40 border-t align-top"
                        :data-assignment="row.id"
                    >
                        <td
                            class="px-3 py-2 font-mono text-xs whitespace-nowrap"
                        >
                            {{ row.reference }}
                            <div class="text-muted-foreground">
                                v{{ row.versionNo }}
                            </div>
                        </td>
                        <td class="px-3 py-2">{{ row.summary }}</td>
                        <td class="px-3 py-2">{{ row.type }}</td>
                        <td class="px-3 py-2">{{ row.course }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <span
                                v-if="row.isOverdue"
                                class="text-destructive inline-flex items-center gap-1"
                            >
                                <AlertTriangle class="size-3" />
                                {{ due(row) }}
                            </span>
                            <span v-else>{{ due(row) }}</span>
                            <Badge
                                v-if="row.status === 'submitted'"
                                variant="secondary"
                                class="ml-1"
                                >{{
                                    row.outcome === 'changes_requested'
                                        ? 'sent back'
                                        : 'reviewed'
                                }}</Badge
                            >
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <Button
                                as-child
                                size="sm"
                                :variant="
                                    row.status === 'open'
                                        ? 'default'
                                        : 'outline'
                                "
                            >
                                <Link
                                    :href="`/questions/${row.questionId}/versions/${row.versionId}/review`"
                                    >{{
                                        row.status === 'open'
                                            ? 'Review'
                                            : 'Open'
                                    }}</Link
                                >
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="assignments.data.length === 0">
                        <td colspan="6" class="px-3 py-10 text-center">
                            <ClipboardCheck
                                class="text-muted-foreground mx-auto mb-2 size-6"
                            />
                            <p class="font-medium">Nothing to review</p>
                            <p class="text-muted-foreground mt-1 text-sm">
                                {{
                                    show === 'open'
                                        ? 'When somebody sends a question for review, it appears here.'
                                        : 'Nothing matches this filter.'
                                }}
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="assignments.last_page > 1"
            class="flex flex-wrap items-center gap-2 text-sm"
        >
            <span class="text-muted-foreground"
                >{{ assignments.from }}–{{ assignments.to }} of
                {{ assignments.total }}</span
            >
            <template v-for="(link, i) in assignments.links" :key="i">
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
