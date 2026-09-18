<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    ClipboardCheck,
    FilePlus2,
    FileQuestion,
} from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index as approvals } from '@/routes/approvals';
import { create, index } from '@/routes/questions';
import { index as reviews } from '@/routes/reviews';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] },
});

const props = defineProps<{
    work: { myReviews: number | null; toApprove: number | null };
    questionBank: {
        visible: boolean;
        total: number;
        mine: number;
        courses: number;
        statuses: { status: string; label: string; count: number }[];
    };
}>();

const page = usePage();
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Question bank & exams"
            :description="`Everything here belongs to ${page.props.branch?.name ?? 'your campus'}. Roles, exam access and the audit log are managed in the CMS.`"
        />

        <p
            v-if="!questionBank.visible"
            class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
            Your roles do not include a Question Bank &amp; Exams permission
            yet, or you have no campus. Ask an administrator to grant one in the
            CMS (Roles &rarr; Assign Permission).
        </p>

        <template v-else>
            <div class="flex flex-wrap items-center gap-2">
                <Button as-child>
                    <Link :href="index()"
                        ><FileQuestion /> Open the question bank</Link
                    >
                </Button>
                <Button
                    v-if="page.props.auth.can?.createQuestions"
                    as-child
                    variant="outline"
                >
                    <Link :href="create()"><FilePlus2 /> Write a question</Link>
                </Button>
                <Button
                    v-if="work.myReviews !== null"
                    as-child
                    :variant="work.myReviews > 0 ? 'default' : 'outline'"
                    data-test="my-reviews"
                >
                    <Link :href="reviews()"
                        ><ClipboardCheck /> My reviews ({{
                            work.myReviews
                        }})</Link
                    >
                </Button>
                <Button
                    v-if="work.toApprove !== null"
                    as-child
                    :variant="work.toApprove > 0 ? 'default' : 'outline'"
                    data-test="to-approve"
                >
                    <Link :href="approvals()"
                        ><BadgeCheck /> To approve ({{ work.toApprove }})</Link
                    >
                </Button>
            </div>

            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border p-4">
                    <dt class="text-muted-foreground text-sm">
                        Questions in this campus
                    </dt>
                    <dd
                        class="text-2xl font-semibold"
                        data-test="total-questions"
                    >
                        {{ questionBank.total }}
                    </dd>
                </div>
                <div class="rounded-xl border p-4">
                    <dt class="text-muted-foreground text-sm">
                        Written by you
                    </dt>
                    <dd class="text-2xl font-semibold">
                        {{ questionBank.mine }}
                    </dd>
                </div>
                <div class="rounded-xl border p-4">
                    <dt class="text-muted-foreground text-sm">
                        Courses you may write for
                    </dt>
                    <dd class="text-2xl font-semibold">
                        {{ questionBank.courses }}
                    </dd>
                </div>
                <div class="rounded-xl border p-4">
                    <dt class="text-muted-foreground text-sm">
                        Ready for exams (active)
                    </dt>
                    <dd class="text-2xl font-semibold">
                        {{
                            questionBank.statuses.find(
                                (row) => row.status === 'active',
                            )?.count ?? 0
                        }}
                    </dd>
                </div>
            </dl>

            <section class="grid gap-3 rounded-xl border p-4">
                <h2 class="font-medium">Where the questions stand</h2>
                <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="row in questionBank.statuses" :key="row.status">
                        <Link
                            :href="index.url({ query: { status: row.status } })"
                            class="hover:bg-accent flex items-center justify-between rounded-md border px-3 py-2 text-sm"
                        >
                            <span>{{ row.label }}</span>
                            <span class="font-semibold">{{ row.count }}</span>
                        </Link>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
