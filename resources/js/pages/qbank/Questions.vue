<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, index } from '@/routes/questions';
import type { CourseOption, Paginated, QuestionListRow } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Question bank', href: index() }] },
});

const props = defineProps<{
    questions: Paginated<QuestionListRow>;
    filters: {
        search: string;
        status: string;
        course_id: number | null;
        mine: boolean;
    };
    courses: CourseOption[];
    statuses: Record<string, number>;
    canCreate: boolean;
}>();

const search = ref(props.filters.search);

function apply(changes: Record<string, unknown>): void {
    const query = {
        search: search.value || undefined,
        status: props.filters.status || undefined,
        course_id: props.filters.course_id ?? undefined,
        mine: props.filters.mine ? 1 : undefined,
        ...changes,
    };
    router.get(index.url(), query, { preserveState: true, replace: true });
}

const statusStyles: Record<string, string> = {
    draft: 'secondary',
    submitted: 'default',
    under_review: 'default',
    changes_requested: 'destructive',
    approved: 'default',
    active: 'default',
};
</script>

<template>
    <Head title="Question bank" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Question bank"
                description="Questions of the campus you are working in. Their course and topic come from the CMS academic structure."
            />
            <Button v-if="canCreate" as-child data-test="new-question">
                <Link :href="create()"><Plus /> New question</Link>
            </Button>
        </div>

        <form
            class="flex flex-wrap items-end gap-2"
            @submit.prevent="apply({})"
        >
            <Input
                v-model="search"
                type="search"
                class="max-w-xs"
                maxlength="100"
                placeholder="Search text or reference"
                aria-label="Search questions"
            />
            <select
                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                :value="filters.course_id ?? ''"
                aria-label="Course"
                @change="
                    apply({
                        course_id:
                            ($event.target as HTMLSelectElement).value ||
                            undefined,
                    })
                "
            >
                <option value="">All courses</option>
                <option
                    v-for="course in courses"
                    :key="course.id"
                    :value="course.id"
                >
                    {{ course.code }} — {{ course.title }}
                </option>
            </select>
            <Button type="submit" variant="outline">Search</Button>
            <Button
                type="button"
                :variant="filters.mine ? 'default' : 'outline'"
                @click="apply({ mine: filters.mine ? undefined : 1 })"
                >Mine</Button
            >
            <Button
                type="button"
                :variant="filters.status === '' ? 'default' : 'outline'"
                @click="apply({ status: undefined })"
                >All statuses</Button
            >
            <Button
                v-for="(count, status) in statuses"
                :key="status"
                type="button"
                :variant="filters.status === status ? 'default' : 'outline'"
                @click="apply({ status })"
            >
                {{ status.replace('_', ' ') }} ({{ count }})
            </Button>
        </form>

        <div class="overflow-x-auto rounded-lg border">
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
                        class="border-t align-top"
                    >
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ row.reference }}
                            <div class="text-muted-foreground text-xs">
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
                        <td class="px-3 py-2 text-right">
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
                        <td
                            colspan="7"
                            class="text-muted-foreground px-3 py-8 text-center"
                        >
                            No questions yet in this campus.
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
