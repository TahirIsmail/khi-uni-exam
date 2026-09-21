<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    BadgeCheck,
    Check,
    ClipboardList,
    Lock,
    Pencil,
    Send,
    Undo2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import ExamJourney from '@/components/exam/ExamJourney.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/exams';
import type {
    BlueprintScreen,
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

const base = computed(() => `/exams/${props.examination.id}`);
const status = computed(() => props.blueprint.status);

const number = (value: number): string =>
    new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(
        value,
    );

const when = (iso: string | null): string =>
    iso === null ? '' : new Date(iso).toLocaleString();

// "Planned" as a share of the total, so it is plain at a glance how far the blueprint is from done.
const planned = computed(() =>
    props.report.totalMarks > 0
        ? Math.min(
              100,
              Math.round(
                  (props.report.plannedMarks / props.report.totalMarks) * 100,
              ),
          )
        : 0,
);

const page = usePage();
const blueprintError = computed(
    () =>
        (page.props.errors as Record<string, string> | undefined)?.blueprint ??
        null,
);

const sendBack = useForm({ reason: '' });
const reopen = useForm({ reason: '' });
const showSendBack = ref(false);
const showReopen = ref(false);

function post(path: string): void {
    router.post(
        `${base.value}/blueprint/${path}`,
        {},
        { preserveScroll: true },
    );
}

const detailRows = computed(() => [
    ['Programme', props.examination.programme],
    ['Year / Semester', props.examination.year],
    ['Examination', props.examination.examType],
    ['Module / Subject', props.examination.course],
    ['Academic session', props.examination.intake ?? '—'],
    ['Date and start time', props.examination.startsAtLabel ?? 'Not fixed yet'],
    ['Duration', `${props.examination.durationMinutes} minutes`],
    ['Total marks', number(props.examination.totalMarks)],
    [
        'Pass mark',
        `${number(props.examination.passPercentage)}% — ${number(props.examination.passMarks)} marks`,
    ],
    [
        'Negative marking',
        props.examination.negativeMarking
            ? props.examination.negativeFraction === null
                ? "Yes — each question's own negative marks"
                : `Yes — ${number(props.examination.negativeFraction)} of the question's marks`
            : 'No',
    ],
]);

const stageStyle = {
    draft: 'secondary',
    submitted: 'outline',
    approved: 'default',
} as const;
</script>

<template>
    <Head :title="examination.reference" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Heading
                    :title="examination.title"
                    :description="`${examination.reference} · ${examination.programme} · ${examination.year}`"
                />
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="can.edit"
                    as-child
                    variant="outline"
                    data-test="edit-exam"
                >
                    <Link :href="`${base}/edit`"
                        ><Pencil /> Change details</Link
                    >
                </Button>
                <Button as-child data-test="open-blueprint">
                    <Link :href="`${base}/blueprint`"
                        ><ClipboardList />
                        {{
                            can.editBlueprint
                                ? 'Write the blueprint'
                                : 'Open the blueprint'
                        }}</Link
                    >
                </Button>
            </div>
        </div>

        <ExamJourney :stage="status" />

        <p
            v-if="status === 'draft' && blueprint.returnReason"
            class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
            data-test="returned"
        >
            <strong>Sent back:</strong> {{ blueprint.returnReason }}
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border shadow-xs" data-test="details">
                <header class="border-b px-4 py-3">
                    <h2 class="font-medium">The examination</h2>
                </header>
                <dl class="grid gap-3 p-4 text-sm">
                    <div
                        v-for="[label, value] in detailRows"
                        :key="label"
                        class="grid gap-x-4 sm:grid-cols-[10rem_minmax(0,1fr)]"
                    >
                        <dt class="text-muted-foreground">{{ label }}</dt>
                        <dd>{{ value }}</dd>
                    </div>
                    <div
                        v-if="examination.instructions"
                        class="grid gap-x-4 sm:grid-cols-[10rem_minmax(0,1fr)]"
                    >
                        <dt class="text-muted-foreground">Instructions</dt>
                        <dd class="whitespace-pre-line">
                            {{ examination.instructions }}
                        </dd>
                    </div>
                </dl>
            </section>

            <div class="flex flex-col gap-6">
                <section
                    class="rounded-xl border shadow-xs"
                    data-test="blueprint-card"
                >
                    <header
                        class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
                    >
                        <h2 class="font-medium">Blueprint</h2>
                        <Badge
                            :variant="stageStyle[status]"
                            data-test="blueprint-status"
                            >{{ blueprint.statusLabel }}</Badge
                        >
                    </header>
                    <div class="grid gap-4 p-4 text-sm">
                        <div>
                            <div class="flex items-baseline justify-between">
                                <span class="text-muted-foreground"
                                    >Planned marks</span
                                >
                                <span class="tabular-nums" data-test="planned"
                                    >{{ number(report.plannedMarks) }} of
                                    {{ number(report.totalMarks) }}</span
                                >
                            </div>
                            <div
                                class="bg-muted mt-1 h-2 overflow-hidden rounded-full"
                            >
                                <div
                                    class="h-full rounded-full"
                                    :class="
                                        report.isBalanced
                                            ? 'bg-green-600'
                                            : 'bg-primary'
                                    "
                                    :style="{ width: `${planned}%` }"
                                />
                            </div>
                            <p class="text-muted-foreground mt-1 text-xs">
                                {{ report.plannedQuestions }} questions in
                                {{ blueprint.rows.length }}
                                {{ blueprint.rows.length === 1 ? 'row' : 'rows'
                                }}<template v-if="blueprint.sections.length > 0"
                                    >,
                                    {{ blueprint.sections.length }}
                                    sections</template
                                >
                            </p>
                        </div>

                        <ul
                            v-if="report.blockers.length > 0"
                            class="grid gap-1 rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                            data-test="blockers"
                        >
                            <li
                                v-for="text in report.blockers"
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
                            <Check class="size-4" /> The rows add up to the
                            total marks.
                        </p>

                        <ul
                            v-if="report.warnings.length > 0"
                            class="text-muted-foreground grid gap-1 text-xs"
                            data-test="warnings"
                        >
                            <li v-for="text in report.warnings" :key="text">
                                • {{ text }}
                            </li>
                        </ul>

                        <p
                            v-if="status === 'submitted'"
                            class="text-muted-foreground text-xs"
                        >
                            Submitted by {{ blueprint.submittedBy }}
                            {{ when(blueprint.submittedAt) }}.
                        </p>
                        <p
                            v-if="status === 'approved'"
                            class="text-muted-foreground text-xs"
                            data-test="approved-by"
                        >
                            Approved by {{ blueprint.approvedBy }}
                            {{ when(blueprint.approvedAt) }}. Fingerprint
                            <code>{{ blueprint.fingerprint }}</code> — the paper
                            is checked against exactly this.
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-if="can.submit"
                                :disabled="report.blockers.length > 0"
                                data-test="submit-blueprint"
                                @click="post('submit')"
                            >
                                <Send /> Submit for approval
                            </Button>
                            <Button
                                v-if="can.approve"
                                :disabled="report.blockers.length > 0"
                                data-test="approve-blueprint"
                                @click="post('approve')"
                            >
                                <BadgeCheck /> Approve
                            </Button>
                            <Button
                                v-if="can.sendBack"
                                variant="outline"
                                data-test="send-back"
                                @click="showSendBack = !showSendBack"
                            >
                                <Undo2 /> Send back
                            </Button>
                            <Button
                                v-if="can.reopen"
                                variant="outline"
                                data-test="reopen"
                                @click="showReopen = !showReopen"
                            >
                                <Undo2 /> Reopen
                            </Button>
                        </div>
                        <p
                            v-if="status === 'submitted' && !can.approve"
                            class="text-muted-foreground text-xs"
                        >
                            Somebody else approves it: nobody approves a
                            blueprint they wrote or submitted.
                        </p>

                        <form
                            v-if="showSendBack && can.sendBack"
                            class="grid gap-2 border-t pt-3"
                            @submit.prevent="
                                sendBack.post(`${base}/blueprint/send-back`, {
                                    preserveScroll: true,
                                })
                            "
                        >
                            <Label for="send-back-reason"
                                >What has to change?</Label
                            >
                            <textarea
                                id="send-back-reason"
                                v-model="sendBack.reason"
                                class="border-input bg-background min-h-20 rounded-md border p-2 text-sm"
                                maxlength="500"
                                data-test="send-back-reason"
                            />
                            <p
                                v-if="sendBack.errors.reason"
                                class="text-destructive text-sm"
                            >
                                {{ sendBack.errors.reason }}
                            </p>
                            <Button
                                type="submit"
                                class="justify-self-start"
                                :disabled="sendBack.processing"
                                data-test="send-back-confirm"
                                >Send it back</Button
                            >
                        </form>

                        <form
                            v-if="showReopen && can.reopen"
                            class="grid gap-2 border-t pt-3"
                            @submit.prevent="
                                reopen.post(`${base}/blueprint/reopen`, {
                                    preserveScroll: true,
                                })
                            "
                        >
                            <Label for="reopen-reason"
                                >Why does the approved blueprint have to
                                change?</Label
                            >
                            <textarea
                                id="reopen-reason"
                                v-model="reopen.reason"
                                class="border-input bg-background min-h-20 rounded-md border p-2 text-sm"
                                maxlength="500"
                                data-test="reopen-reason"
                            />
                            <p
                                v-if="reopen.errors.reason"
                                class="text-destructive text-sm"
                            >
                                {{ reopen.errors.reason }}
                            </p>
                            <Button
                                type="submit"
                                class="justify-self-start"
                                :disabled="reopen.processing"
                                data-test="reopen-confirm"
                                >Reopen it</Button
                            >
                        </form>

                        <p
                            v-if="blueprintError"
                            class="text-destructive text-sm"
                        >
                            {{ blueprintError }}
                        </p>
                    </div>
                </section>

                <section
                    class="text-muted-foreground rounded-xl border border-dashed p-4 text-sm"
                    data-test="paper-next"
                >
                    <div class="flex items-center gap-2 font-medium">
                        <Lock class="size-4" /> Paper
                    </div>
                    <p class="mt-1">
                        {{
                            status === 'approved'
                                ? 'The blueprint is approved. Building the paper from the question bank, moderating it and locking it is the next step of the module.'
                                : 'Opens once the blueprint is approved: the paper is built from the question bank to match it.'
                        }}
                    </p>
                </section>
            </div>
        </div>
    </div>
</template>
