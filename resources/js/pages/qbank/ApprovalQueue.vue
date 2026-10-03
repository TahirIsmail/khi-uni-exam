<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { BadgeCheck, Lock } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index as approvals } from '@/routes/approvals';
import { index as questions } from '@/routes/questions';
import type { ApprovalRow, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Question bank', href: questions() },
            { title: 'Approvals', href: approvals() },
        ],
    },
});

defineProps<{
    versions: Paginated<ApprovalRow>;
    show: string;
    academicReview: boolean;
}>();

function filter(show: string): void {
    router.get(approvals.url(), show === 'ready' ? {} : { show }, {
        preserveState: true,
        replace: true,
    });
}
</script>

<template>
    <Head title="Approvals" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Approvals"
            :description="
                academicReview
                    ? 'Questions that have been reviewed and are waiting for a decision. Each has been through its department / subject review and its QBank / academic review. Approving records the cognitive and difficulty level the question keeps.'
                    : 'Questions whose reviewer did not simply accept them, waiting for a decision. A question the reviewer accepts is stored in the QBank without coming here.'
            "
        />

        <div class="flex flex-wrap items-center gap-2">
            <Button
                size="sm"
                :variant="show === 'ready' ? 'default' : 'outline'"
                data-test="show-ready"
                @click="filter('ready')"
                >Ready to decide</Button
            >
            <Button
                size="sm"
                :variant="show === 'waiting' ? 'default' : 'outline'"
                data-test="show-waiting"
                @click="filter('waiting')"
                >Still in review</Button
            >
            <Button
                size="sm"
                :variant="show === 'approved' ? 'default' : 'outline'"
                @click="filter('approved')"
                >Approved, not in use</Button
            >
            <Button
                size="sm"
                :variant="show === 'all' ? 'default' : 'outline'"
                @click="filter('all')"
                >All in review</Button
            >
        </div>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Reference</th>
                        <th class="px-3 py-2 font-medium">Question</th>
                        <th class="px-3 py-2 font-medium">Course</th>
                        <th class="px-3 py-2 font-medium">Reviews</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2">
                            <span class="sr-only">Open</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in versions.data"
                        :key="row.versionId"
                        class="hover:bg-muted/40 border-t align-top"
                        :data-version="row.versionId"
                    >
                        <td
                            class="px-3 py-2 font-mono text-xs whitespace-nowrap"
                        >
                            {{ row.reference }}
                            <div class="text-muted-foreground">
                                v{{ row.versionNo }}
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            {{ row.summary }}
                            <p
                                v-if="row.blockedBecause"
                                class="text-muted-foreground mt-1 flex items-start gap-1 text-xs"
                                :data-blocked="row.versionId"
                            >
                                <Lock class="mt-0.5 size-3 shrink-0" />
                                {{ row.blockedBecause }}
                            </p>
                        </td>
                        <td class="px-3 py-2">{{ row.course }}</td>
                        <td class="px-3 py-2 tabular-nums">
                            <div>
                                {{ academicReview ? 'Subject' : 'Reviewed' }}
                                {{ row.subjectIn }} of
                                {{ row.subjectNeeded }}
                            </div>
                            <div
                                v-if="academicReview"
                                class="text-muted-foreground"
                            >
                                Academic {{ row.academicIn }} of 1
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="
                                    row.status === 'approved'
                                        ? 'default'
                                        : 'secondary'
                                "
                                >{{ row.statusLabel }}</Badge
                            >
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <Button
                                as-child
                                size="sm"
                                :variant="
                                    row.blockedBecause ? 'outline' : 'default'
                                "
                            >
                                <Link
                                    :href="`/questions/${row.questionId}/versions/${row.versionId}/review`"
                                    >{{
                                        row.blockedBecause ? 'Open' : 'Decide'
                                    }}</Link
                                >
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="versions.data.length === 0">
                        <td colspan="6" class="px-3 py-10 text-center">
                            <BadgeCheck
                                class="text-muted-foreground mx-auto mb-2 size-6"
                            />
                            <p class="font-medium">Nothing to decide</p>
                            <p class="text-muted-foreground mt-1 text-sm">
                                A question appears here once its department /
                                subject reviews{{
                                    academicReview
                                        ? ' and its QBank / academic review'
                                        : ''
                                }}
                                are in.
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="versions.last_page > 1"
            class="flex flex-wrap items-center gap-2 text-sm"
        >
            <span class="text-muted-foreground"
                >{{ versions.from }}–{{ versions.to }} of
                {{ versions.total }}</span
            >
            <template v-for="(link, i) in versions.links" :key="i">
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
