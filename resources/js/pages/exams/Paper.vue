<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeftRight,
    BadgeCheck,
    Check,
    FilePlus2,
    Lock,
    LockOpen,
    MessageSquare,
    Plus,
    Search,
    Send,
    Shuffle,
    Sparkles,
    Trash2,
    Undo2,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import ExamJourney from '@/components/exam/ExamJourney.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/exams';
import type {
    ExaminationDetail,
    PaperCandidate,
    PaperCommentData,
    PaperItemData,
    PaperMixRow,
    PaperRowData,
    PaperScreen,
} from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Examinations', href: index() }] },
});

const props = defineProps<
    {
        examination: ExaminationDetail;
    } & PaperScreen
>();

const page = usePage();
const base = computed(() => `/exams/${props.examination.id}`);
const canOpenQuestions = computed(
    () => page.props.auth.can?.viewQuestions === true,
);
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);
const problem = computed(
    () =>
        errors.value.paper ??
        errors.value.slot ??
        errors.value.question_id ??
        errors.value.item ??
        null,
);

const number = (value: number): string =>
    new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(
        value,
    );

const progress = computed(() =>
    props.totals.planned > 0
        ? Math.min(
              100,
              Math.round((props.totals.chosen / props.totals.planned) * 100),
          )
        : 0,
);
const complete = computed(
    () =>
        props.totals.chosen === props.totals.planned &&
        props.totals.planned > 0 &&
        props.unassigned.length === 0 &&
        props.rows.every((row) => row.over === 0),
);

// ---- filling and settings ---------------------------------------------------------------------
const working = ref(false);

function fill(mode: 'gaps' | 'redraw'): void {
    if (
        mode === 'redraw' &&
        !window.confirm(
            'Take out every question that is not locked and draw the paper again?',
        )
    ) {
        return;
    }
    router.post(
        `${base.value}/paper/fill`,
        { mode },
        {
            preserveScroll: true,
            onStart: () => {
                working.value = true;
            },
            onFinish: () => {
                working.value = false;
            },
        },
    );
}

function setShuffle(questions: boolean, options: boolean): void {
    router.put(
        `${base.value}/paper`,
        { shuffle_questions: questions, shuffle_options: options },
        { preserveScroll: true },
    );
}

function start(): void {
    router.post(`${base.value}/paper`, {}, { preserveScroll: true });
}

// ---- one question at a time -------------------------------------------------------------------
function lock(item: PaperItemData): void {
    router.post(
        `${base.value}/paper/items/${item.id}/lock`,
        { locked: !item.isLocked },
        { preserveScroll: true },
    );
}

function remove(item: PaperItemData): void {
    router.delete(`${base.value}/paper/items/${item.id}`, {
        preserveScroll: true,
    });
}

// ---- the picker: add a question to a row, or swap one for another ------------------------------
type Picker = { row: PaperRowData; swap: PaperItemData | null };
const picker = ref<Picker | null>(null);
const candidates = ref<PaperCandidate[]>([]);
const search = ref('');
const loading = ref(false);
let timer: number | undefined;

async function load(): Promise<void> {
    if (picker.value === null) {
        return;
    }
    const row = picker.value.row;
    loading.value = true;
    const query = new URLSearchParams({
        node_id: String(row.nodeId),
        question_type_id: String(row.typeId),
        marks_each: String(row.marks),
        search: search.value,
    });
    if (row.section !== null) {
        query.set('section', row.section);
    }
    try {
        const response = await fetch(
            `${base.value}/paper/candidates?${query.toString()}`,
            { headers: { Accept: 'application/json' } },
        );
        candidates.value = response.ok
            ? ((await response.json()) as { candidates: PaperCandidate[] })
                  .candidates
            : [];
    } finally {
        loading.value = false;
    }
}

function open(row: PaperRowData, swap: PaperItemData | null = null): void {
    picker.value = { row, swap };
    search.value = '';
    candidates.value = [];
    void load();
}

function close(): void {
    picker.value = null;
}

function typed(): void {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => void load(), 300);
}

function choose(candidate: PaperCandidate): void {
    if (picker.value === null) {
        return;
    }
    const { row, swap } = picker.value;
    const done = { preserveScroll: true, onSuccess: close };

    if (swap !== null) {
        router.post(
            `${base.value}/paper/items/${swap.id}/swap`,
            { question_id: candidate.questionId },
            done,
        );

        return;
    }
    router.post(
        `${base.value}/paper/items`,
        {
            node_id: row.nodeId,
            question_type_id: row.typeId,
            marks_each: row.marks,
            section: row.section,
            question_id: candidate.questionId,
        },
        done,
    );
}

// ---- moderating and locking it ------------------------------------------------------------------
const acting = ref(false);

function post(
    path: string,
    data: Record<string, string | number | boolean> = {},
): void {
    router.post(`${base.value}/paper/${path}`, data, {
        preserveScroll: true,
        onStart: () => {
            acting.value = true;
        },
        onFinish: () => {
            acting.value = false;
        },
    });
}

function submit(): void {
    post('submit');
}
function approve(): void {
    post('approve');
}
const showFinaliseConfirm = ref(false);
function finalise(): void {
    showFinaliseConfirm.value = false;
    post('finalise');
}
function publish(): void {
    post('publish');
}
const showNewVersionConfirm = ref(false);
function newVersion(): void {
    showNewVersionConfirm.value = false;
    post('new-version');
}

const sendBackForm = useForm({ reason: '' });
const showSendBack = ref(false);
function sendBack(): void {
    sendBackForm.post(`${base.value}/paper/send-back`, {
        preserveScroll: true,
        onSuccess: () => {
            showSendBack.value = false;
            sendBackForm.reset();
        },
    });
}

// ---- comments -------------------------------------------------------------------------------
const newComment = ref('');
const newCommentItem = ref<number | null>(null);
const commentForm = useForm({ item_id: null as number | null, body: '' });

function addComment(): void {
    commentForm.item_id = newCommentItem.value;
    commentForm.body = newComment.value;
    commentForm.post(`${base.value}/paper/comments`, {
        preserveScroll: true,
        onSuccess: () => {
            newComment.value = '';
            newCommentItem.value = null;
        },
    });
}

function resolveComment(comment: PaperCommentData, resolved: boolean): void {
    router.post(
        `${base.value}/paper/comments/${comment.id}/resolve`,
        { resolved },
        { preserveScroll: true },
    );
}

const allItems = computed<PaperItemData[]>(() =>
    props.rows.flatMap((row) => row.items),
);

const statusStyle: Record<string, 'secondary' | 'outline' | 'default'> = {
    draft: 'secondary',
    submitted: 'outline',
    approved: 'outline',
    finalised: 'default',
    published: 'default',
};

const flagText: Record<string, string> = {
    recent: `Used in the last ${props.limits.recentMonths} months`,
    own: 'You wrote it',
    newer: 'A newer version is in use',
    gone: 'No longer in use',
    same_text: 'Same text as another',
};

const mixes = computed<[string, PaperMixRow[]][]>(() => [
    ['Cognitive level', props.mix.cognitive],
    ['Difficulty', props.mix.difficulty],
]);
const showMix = (rows: PaperMixRow[]): boolean =>
    rows.some((row) => row.target !== null || row.count > 0);
</script>

<template>
    <Head :title="`${examination.reference} — paper`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`Paper — ${examination.title}`"
                :description="`${examination.reference} · ${examination.course} · ${examination.examType} · ${number(examination.totalMarks)} marks`"
            />
            <Button as-child variant="ghost">
                <Link :href="base">Back to the examination</Link>
            </Button>
        </div>

        <ExamJourney
            :stage="blueprintApproved ? 'approved' : 'draft'"
            :exam-id="examination.id"
            :paper-status="paper?.status"
        />

        <p
            v-if="problem"
            class="border-destructive/40 bg-destructive/5 text-destructive rounded-lg border p-4 text-sm"
            data-test="problem"
        >
            {{ problem }}
        </p>

        <!-- The paper is built to an approved blueprint, and to nothing else. -->
        <section
            v-if="!blueprintApproved"
            class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
            data-test="not-approved"
        >
            The blueprint has to be approved before the paper can be built or
            changed.
            <Link :href="`${base}/blueprint`" class="underline"
                >Open the blueprint</Link
            >.
        </section>

        <section
            v-if="paper === null"
            class="rounded-xl border p-6 shadow-xs"
            data-test="start"
        >
            <h2 class="font-medium">The paper has not been started</h2>
            <p class="text-muted-foreground mt-1 text-sm">
                {{
                    can.start
                        ? 'Start it, then fill it from the question bank: the blueprint says what it needs and the bank is drawn on to match. You can then swap, add or lock questions yourself.'
                        : blueprintApproved
                          ? 'Nobody has started it yet.'
                          : 'It can be started once the blueprint is approved.'
                }}
            </p>
            <Button
                v-if="can.start"
                class="mt-4"
                data-test="start-paper"
                @click="start"
            >
                <Plus /> Start the paper
            </Button>
        </section>

        <template v-else>
            <!-- Where the paper is, whom it needs next, and what a person with the right may do. -->
            <section class="rounded-xl border shadow-xs" data-test="workflow">
                <header
                    class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
                >
                    <div class="flex items-center gap-2">
                        <h2 class="font-medium">Moderating and locking it</h2>
                        <Badge
                            :variant="statusStyle[paper.status]"
                            data-test="paper-status"
                            >{{ paper.statusLabel }}</Badge
                        >
                    </div>
                    <div
                        v-if="versions.length > 1"
                        class="flex flex-wrap items-center gap-1"
                        data-test="versions"
                    >
                        <span class="text-muted-foreground text-xs"
                            >Version</span
                        >
                        <Link
                            v-for="version in versions"
                            :key="version.id"
                            :href="`${base}/paper?version=${version.versionNo}`"
                            :data-version="version.versionNo"
                        >
                            <Badge
                                :variant="
                                    version.isCurrent ? 'default' : 'outline'
                                "
                                >{{ version.versionNo }}</Badge
                            >
                        </Link>
                    </div>
                </header>

                <div class="grid gap-3 p-4 text-sm">
                    <p
                        v-if="paper.returnReason"
                        class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                        data-test="return-reason"
                    >
                        <strong>Sent back:</strong> {{ paper.returnReason }}
                    </p>

                    <ul
                        v-if="report && report.blockers.length > 0"
                        class="grid gap-1 rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                        data-test="report-blockers"
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
                        v-else-if="report"
                        class="flex items-center gap-2 text-green-700 dark:text-green-400"
                        data-test="report-sound"
                    >
                        <Check class="size-4" /> Ready for the next step.
                    </p>
                    <ul
                        v-if="report && report.advisories.length > 0"
                        class="text-muted-foreground grid gap-1 text-xs"
                        data-test="report-advisories"
                    >
                        <li v-for="text in report.advisories" :key="text">
                            • {{ text }}
                        </li>
                    </ul>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="can.submit"
                            :disabled="acting"
                            data-test="submit-paper"
                            @click="submit"
                        >
                            <Send /> Submit for moderation
                        </Button>
                        <Button
                            v-if="can.approve"
                            :disabled="acting"
                            data-test="approve-paper"
                            @click="approve"
                        >
                            <BadgeCheck /> Approve
                        </Button>
                        <Button
                            v-if="can.finalise"
                            :disabled="acting"
                            data-test="finalise-paper"
                            @click="showFinaliseConfirm = !showFinaliseConfirm"
                        >
                            <Lock /> Finalise and lock
                        </Button>
                        <Button
                            v-if="can.publish"
                            :disabled="acting"
                            data-test="publish-paper"
                            @click="publish"
                        >
                            <Send /> Publish
                        </Button>
                        <Button
                            v-if="can.sendBack"
                            variant="outline"
                            :disabled="acting"
                            data-test="send-back-paper"
                            @click="showSendBack = !showSendBack"
                        >
                            <Undo2 /> Send back
                        </Button>
                        <Button
                            v-if="can.unlockVersion"
                            variant="outline"
                            :disabled="acting"
                            data-test="new-version"
                            @click="
                                showNewVersionConfirm = !showNewVersionConfirm
                            "
                        >
                            <FilePlus2 /> New version to correct it
                        </Button>
                    </div>

                    <div
                        v-if="showFinaliseConfirm"
                        class="grid gap-2 border-t pt-3"
                    >
                        <p class="text-sm">
                            Finalise this paper? Once finalised it is locked: a
                            correction means a new version.
                        </p>
                        <Button
                            class="justify-self-start"
                            :disabled="acting"
                            data-test="finalise-confirm"
                            @click="finalise"
                            >Finalise it</Button
                        >
                    </div>

                    <div
                        v-if="showNewVersionConfirm"
                        class="grid gap-2 border-t pt-3"
                    >
                        <p class="text-sm">
                            Start a new version to correct this paper? The
                            finalised one is kept exactly as it was.
                        </p>
                        <Button
                            class="justify-self-start"
                            :disabled="acting"
                            data-test="new-version-confirm"
                            @click="newVersion"
                            >Start a new version</Button
                        >
                    </div>
                    <p
                        v-if="paper.status === 'submitted' && !can.approve"
                        class="text-muted-foreground text-xs"
                    >
                        Nobody approves a paper they started or submitted.
                    </p>

                    <form
                        v-if="showSendBack"
                        class="grid gap-2 border-t pt-3"
                        @submit.prevent="sendBack"
                    >
                        <Label for="paper-send-back-reason"
                            >What has to change?</Label
                        >
                        <textarea
                            id="paper-send-back-reason"
                            v-model="sendBackForm.reason"
                            class="border-input bg-background min-h-20 rounded-md border p-2 text-sm"
                            maxlength="500"
                            data-test="send-back-reason"
                        />
                        <p
                            v-if="sendBackForm.errors.reason"
                            class="text-destructive text-sm"
                        >
                            {{ sendBackForm.errors.reason }}
                        </p>
                        <Button
                            type="submit"
                            class="justify-self-start"
                            :disabled="sendBackForm.processing"
                            data-test="send-back-confirm"
                            >Send it back</Button
                        >
                    </form>
                </div>
            </section>

            <!-- Comments left while the paper is moderated. -->
            <section
                v-if="paper.status !== 'draft'"
                class="rounded-xl border shadow-xs"
                data-test="comments"
            >
                <header class="border-b px-4 py-3">
                    <h2 class="flex items-center gap-2 font-medium">
                        <MessageSquare class="size-4" /> Comments
                        <span
                            v-if="comments.length > 0"
                            class="text-muted-foreground font-normal"
                            >({{
                                comments.filter((c) => c.status === 'open')
                                    .length
                            }}
                            open)</span
                        >
                    </h2>
                </header>
                <div class="grid gap-3 p-4 text-sm">
                    <article
                        v-for="comment in comments"
                        :key="comment.id"
                        class="grid gap-1 border-b pb-3 last:border-0 last:pb-0"
                        :data-comment="comment.id"
                    >
                        <header class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{
                                comment.createdBy
                            }}</span>
                            <Badge
                                v-if="comment.itemReference"
                                variant="outline"
                                >{{ comment.itemReference }}</Badge
                            >
                            <Badge
                                :variant="
                                    comment.status === 'open'
                                        ? 'outline'
                                        : 'secondary'
                                "
                                >{{ comment.status }}</Badge
                            >
                        </header>
                        <p class="whitespace-pre-line">{{ comment.body }}</p>
                        <div v-if="can.resolveComments" class="mt-1">
                            <Button
                                size="sm"
                                variant="ghost"
                                :data-resolve="comment.id"
                                @click="
                                    resolveComment(
                                        comment,
                                        comment.status !== 'resolved',
                                    )
                                "
                            >
                                {{
                                    comment.status === 'resolved'
                                        ? 'Reopen'
                                        : 'Mark resolved'
                                }}
                            </Button>
                        </div>
                    </article>
                    <p
                        v-if="comments.length === 0"
                        class="text-muted-foreground"
                    >
                        No comments yet.
                    </p>

                    <form
                        v-if="can.comment"
                        class="grid gap-2 border-t pt-3"
                        @submit.prevent="addComment"
                    >
                        <div class="grid gap-1.5 sm:grid-cols-[1fr_auto]">
                            <textarea
                                v-model="newComment"
                                class="border-input bg-background min-h-16 rounded-md border p-2 text-sm"
                                maxlength="2000"
                                placeholder="A comment on the whole paper, or choose a question below"
                                data-test="new-comment"
                            />
                            <select
                                v-model="newCommentItem"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm sm:self-start"
                                data-test="comment-item"
                            >
                                <option :value="null">General comment</option>
                                <option
                                    v-for="item in allItems"
                                    :key="item.id"
                                    :value="item.id"
                                >
                                    {{ item.reference }}
                                </option>
                            </select>
                        </div>
                        <p
                            v-if="commentForm.errors.body"
                            class="text-destructive text-sm"
                        >
                            {{ commentForm.errors.body }}
                        </p>
                        <Button
                            type="submit"
                            class="justify-self-start"
                            :disabled="commentForm.processing || !newComment"
                            data-test="add-comment"
                            >Add comment</Button
                        >
                    </form>
                </div>
            </section>

            <p
                v-if="paper.blueprintChanged"
                class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                data-test="blueprint-changed"
            >
                The blueprint was approved again after this paper was last
                drawn. Check each row below against it.
            </p>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div class="grid content-start gap-6">
                    <section
                        v-for="row in rows"
                        :key="row.key"
                        class="rounded-xl border shadow-xs"
                        :data-row="row.key"
                    >
                        <header
                            class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
                        >
                            <div>
                                <h2 class="font-medium">{{ row.topic }}</h2>
                                <p class="text-muted-foreground text-xs">
                                    {{ row.typeName }} · {{ number(row.marks) }}
                                    {{ row.marks === 1 ? 'mark' : 'marks' }}
                                    each<template v-if="row.section">
                                        · {{ row.section }}</template
                                    >
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <Badge
                                    :variant="
                                        row.missing === 0 && row.over === 0
                                            ? 'default'
                                            : row.over > 0
                                              ? 'destructive'
                                              : 'outline'
                                    "
                                    data-test="row-progress"
                                    >{{ row.items.length }} of
                                    {{ row.count }}</Badge
                                >
                                <Button
                                    v-if="can.edit && row.missing > 0"
                                    size="sm"
                                    variant="outline"
                                    data-test="add-question"
                                    @click="open(row)"
                                >
                                    <Plus /> Add a question
                                </Button>
                            </div>
                        </header>

                        <ul class="divide-y">
                            <li
                                v-for="item in row.items"
                                :key="item.id"
                                class="grid gap-2 px-4 py-3 text-sm"
                                :data-item="item.id"
                            >
                                <div
                                    class="flex flex-wrap items-start justify-between gap-2"
                                >
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <component
                                                :is="
                                                    canOpenQuestions
                                                        ? Link
                                                        : 'span'
                                                "
                                                :href="
                                                    canOpenQuestions
                                                        ? `/questions/${item.questionId}`
                                                        : undefined
                                                "
                                                class="font-mono text-xs"
                                                :class="
                                                    canOpenQuestions
                                                        ? 'underline-offset-4 hover:underline'
                                                        : ''
                                                "
                                                >{{ item.reference }}</component
                                            >
                                            <span
                                                class="text-muted-foreground text-xs"
                                                >v{{ item.versionNo }}</span
                                            >
                                            <Badge
                                                v-if="item.isLocked"
                                                variant="secondary"
                                                data-test="locked"
                                                ><Lock /> Locked</Badge
                                            >
                                            <Badge
                                                v-if="item.source === 'manual'"
                                                variant="outline"
                                                >chosen by hand</Badge
                                            >
                                        </div>
                                        <p
                                            v-if="item.summary"
                                            class="mt-1"
                                            data-test="summary"
                                        >
                                            {{ item.summary }}
                                        </p>
                                        <p
                                            v-else
                                            class="text-muted-foreground mt-1"
                                        >
                                            The text is hidden: you may count
                                            this paper, not read it.
                                        </p>
                                    </div>
                                    <div
                                        v-if="can.edit"
                                        class="flex shrink-0 items-center gap-1"
                                    >
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            :title="
                                                item.isLocked
                                                    ? 'Unlock it'
                                                    : 'Lock it: a new draw keeps it'
                                            "
                                            data-test="lock"
                                            @click="lock(item)"
                                        >
                                            <LockOpen v-if="item.isLocked" />
                                            <Lock v-else />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            title="Swap it for another"
                                            :disabled="item.isLocked"
                                            data-test="swap"
                                            @click="open(row, item)"
                                        >
                                            <ArrowLeftRight />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            title="Take it out"
                                            :disabled="item.isLocked"
                                            data-test="remove"
                                            @click="remove(item)"
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                </div>
                                <div
                                    class="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-xs"
                                >
                                    <span v-if="item.cognitive">{{
                                        item.cognitive
                                    }}</span>
                                    <span v-if="item.difficulty">{{
                                        item.difficulty
                                    }}</span>
                                    <span>{{
                                        item.timesUsed === 0
                                            ? 'Never used'
                                            : `Used ${item.timesUsed}×, last ${item.lastUsed}`
                                    }}</span>
                                    <Badge
                                        v-for="flag in item.flags"
                                        :key="flag"
                                        variant="outline"
                                        :data-flag="flag"
                                        >{{ flagText[flag] }}</Badge
                                    >
                                    <span v-if="item.latestVersionNo"
                                        >(now v{{ item.latestVersionNo }})</span
                                    >
                                </div>
                            </li>
                            <li
                                v-if="row.items.length === 0"
                                class="text-muted-foreground px-4 py-6 text-center text-sm"
                            >
                                No question chosen for this row yet.
                            </li>
                        </ul>

                        <p
                            v-if="row.missing > 0 && row.available !== null"
                            class="text-muted-foreground border-t px-4 py-2 text-xs"
                            :class="
                                row.available < row.missing
                                    ? 'text-destructive'
                                    : ''
                            "
                            data-test="row-available"
                        >
                            {{ row.missing }} more needed;
                            {{ row.available }} more in the question bank for
                            this row.
                        </p>

                        <!-- The picker for this row. -->
                        <div
                            v-if="picker && picker.row.key === row.key"
                            class="grid gap-3 border-t p-4"
                            data-test="picker"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <h3 class="text-sm font-medium">
                                    {{
                                        picker.swap
                                            ? `Swap ${picker.swap.reference} for`
                                            : 'Choose a question for this row'
                                    }}
                                </h3>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    title="Close"
                                    @click="close"
                                >
                                    <X />
                                </Button>
                            </div>
                            <div class="relative">
                                <Search
                                    class="text-muted-foreground absolute top-2.5 left-2.5 size-4"
                                />
                                <Input
                                    v-model="search"
                                    class="pl-8"
                                    placeholder="Search the question text or reference"
                                    maxlength="100"
                                    data-test="picker-search"
                                    @input="typed"
                                />
                            </div>
                            <ul class="grid gap-2">
                                <li
                                    v-for="candidate in candidates"
                                    :key="candidate.questionId"
                                    class="flex flex-wrap items-start justify-between gap-2 rounded-lg border p-3 text-sm"
                                    :data-candidate="candidate.questionId"
                                >
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <span class="font-mono text-xs">{{
                                                candidate.reference
                                            }}</span>
                                            <span
                                                class="text-muted-foreground text-xs"
                                                >{{ candidate.topic }}</span
                                            >
                                            <Badge
                                                v-if="candidate.usedRecently"
                                                variant="outline"
                                                >Used lately</Badge
                                            >
                                            <Badge
                                                v-if="candidate.sameTextInPaper"
                                                variant="outline"
                                                >Same text as one in the
                                                paper</Badge
                                            >
                                            <Badge
                                                v-if="candidate.mine"
                                                variant="outline"
                                                >You wrote it</Badge
                                            >
                                        </div>
                                        <p class="mt-1">
                                            {{ candidate.summary }}
                                        </p>
                                        <p
                                            class="text-muted-foreground mt-1 text-xs"
                                        >
                                            {{ candidate.cognitive ?? '—' }} ·
                                            {{ candidate.difficulty ?? '—' }} ·
                                            {{
                                                candidate.timesUsed === 0
                                                    ? 'never used'
                                                    : `used ${candidate.timesUsed}×, last ${candidate.lastUsed}`
                                            }}
                                        </p>
                                    </div>
                                    <Button
                                        size="sm"
                                        data-test="choose"
                                        @click="choose(candidate)"
                                    >
                                        Choose
                                    </Button>
                                </li>
                                <li
                                    v-if="!loading && candidates.length === 0"
                                    class="text-muted-foreground text-sm"
                                >
                                    {{
                                        search
                                            ? 'No question matches those words.'
                                            : 'The question bank has nothing more for this row.'
                                    }}
                                </li>
                                <li
                                    v-if="
                                        candidates.length >= limits.candidates
                                    "
                                    class="text-muted-foreground text-xs"
                                >
                                    The first {{ limits.candidates }} are shown:
                                    search to narrow them.
                                </li>
                            </ul>
                        </div>
                    </section>

                    <section
                        v-if="unassigned.length > 0"
                        class="rounded-xl border border-amber-300 shadow-xs dark:border-amber-800"
                        data-test="unassigned"
                    >
                        <header class="border-b px-4 py-3">
                            <h2 class="font-medium">
                                Not in the blueprint any more
                            </h2>
                            <p class="text-muted-foreground text-xs">
                                These were chosen for a row that has since been
                                changed or removed. Take them out, or restore
                                the row.
                            </p>
                        </header>
                        <ul class="divide-y">
                            <li
                                v-for="item in unassigned"
                                :key="item.id"
                                class="flex items-start justify-between gap-2 px-4 py-3 text-sm"
                            >
                                <div>
                                    <span class="font-mono text-xs">{{
                                        item.reference
                                    }}</span>
                                    <p v-if="item.summary" class="mt-1">
                                        {{ item.summary }}
                                    </p>
                                </div>
                                <Button
                                    v-if="can.edit"
                                    size="icon"
                                    variant="ghost"
                                    title="Take it out"
                                    :disabled="item.isLocked"
                                    @click="remove(item)"
                                >
                                    <Trash2 />
                                </Button>
                            </li>
                        </ul>
                    </section>
                </div>

                <aside class="grid content-start gap-6 xl:sticky xl:top-4">
                    <section
                        class="rounded-xl border shadow-xs"
                        data-test="totals"
                    >
                        <header class="border-b px-4 py-3">
                            <h2 class="font-medium">Is it complete?</h2>
                        </header>
                        <div class="grid gap-3 p-4 text-sm">
                            <div class="flex items-baseline justify-between">
                                <span class="text-muted-foreground"
                                    >Questions</span
                                >
                                <span class="tabular-nums" data-test="chosen"
                                    >{{ totals.chosen }} of
                                    {{ totals.planned }}</span
                                >
                            </div>
                            <div
                                class="bg-muted h-2 overflow-hidden rounded-full"
                            >
                                <div
                                    class="h-full rounded-full"
                                    :class="
                                        complete ? 'bg-green-600' : 'bg-primary'
                                    "
                                    :style="{ width: `${progress}%` }"
                                />
                            </div>
                            <div class="flex items-baseline justify-between">
                                <span class="text-muted-foreground">Marks</span>
                                <span class="tabular-nums" data-test="marks"
                                    >{{ number(totals.marks) }} of
                                    {{ number(totals.totalMarks) }}</span
                                >
                            </div>
                            <p
                                v-if="complete"
                                class="flex items-center gap-2 text-green-700 dark:text-green-400"
                                data-test="complete"
                            >
                                <Check class="size-4" /> Every row is full.
                            </p>

                            <div
                                v-if="can.edit"
                                class="grid gap-2 border-t pt-3"
                            >
                                <Button
                                    :disabled="working"
                                    data-test="fill-gaps"
                                    @click="fill('gaps')"
                                >
                                    <Sparkles /> Fill the gaps from the bank
                                </Button>
                                <Button
                                    variant="outline"
                                    :disabled="working"
                                    data-test="redraw"
                                    @click="fill('redraw')"
                                >
                                    Draw again, keeping locked ones
                                </Button>
                                <p class="text-muted-foreground text-xs">
                                    The draw aims for the blueprint's mix and
                                    prefers questions not used lately. It never
                                    puts the same question or the same text in
                                    twice.
                                </p>
                            </div>
                        </div>
                    </section>

                    <section
                        v-if="warnings.length > 0"
                        class="rounded-xl border shadow-xs"
                        data-test="warnings"
                    >
                        <header class="border-b px-4 py-3">
                            <h2 class="font-medium">Worth a second look</h2>
                        </header>
                        <ul class="grid gap-3 p-4 text-sm">
                            <li
                                v-for="warning in warnings"
                                :key="warning.kind"
                                class="grid gap-1"
                                :data-warning="warning.kind"
                            >
                                <p class="flex gap-2">
                                    <AlertTriangle
                                        class="mt-0.5 size-4 shrink-0 text-amber-600"
                                    />
                                    {{ warning.message }}
                                </p>
                                <p
                                    class="text-muted-foreground pl-6 font-mono text-xs"
                                >
                                    {{ warning.references.join(', ') }}
                                </p>
                            </li>
                        </ul>
                    </section>

                    <section class="rounded-xl border shadow-xs">
                        <header class="border-b px-4 py-3">
                            <h2 class="font-medium">
                                How each candidate meets it
                            </h2>
                        </header>
                        <div class="grid gap-2 p-4 text-sm">
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="paper.shuffleQuestions"
                                    :disabled="!can.edit"
                                    data-test="shuffle-questions"
                                    @change="
                                        setShuffle(
                                            ($event.target as HTMLInputElement)
                                                .checked,
                                            paper.shuffleOptions,
                                        )
                                    "
                                />
                                <Shuffle class="size-4" /> Questions in an order
                                of their own
                            </label>
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    class="size-4"
                                    :checked="paper.shuffleOptions"
                                    :disabled="!can.edit"
                                    data-test="shuffle-options"
                                    @change="
                                        setShuffle(
                                            paper.shuffleQuestions,
                                            ($event.target as HTMLInputElement)
                                                .checked,
                                        )
                                    "
                                />
                                <Shuffle class="size-4" /> Options in an order
                                of their own
                            </label>
                            <p class="text-muted-foreground text-xs">
                                Different for every candidate is the safe
                                choice: neighbours cannot read across. Turn it
                                off for a paper that has to be read in one
                                order.
                            </p>
                        </div>
                    </section>

                    <section
                        v-for="[title, rows] in mixes"
                        v-show="showMix(rows)"
                        :key="title"
                        class="rounded-xl border shadow-xs"
                        :data-test="`mix-${title}`"
                    >
                        <header class="border-b px-4 py-3">
                            <h2 class="font-medium">{{ title }}</h2>
                            <p class="text-muted-foreground text-xs">
                                What the blueprint asks for, and what the paper
                                has.
                            </p>
                        </header>
                        <table class="w-full text-sm">
                            <thead class="text-muted-foreground text-xs">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium">
                                        Level
                                    </th>
                                    <th
                                        class="px-2 py-2 text-right font-medium"
                                    >
                                        Asked
                                    </th>
                                    <th
                                        class="px-4 py-2 text-right font-medium"
                                    >
                                        Has
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in rows"
                                    :key="row.id"
                                    class="border-t"
                                >
                                    <td class="px-4 py-1.5">{{ row.name }}</td>
                                    <td
                                        class="text-muted-foreground px-2 py-1.5 text-right tabular-nums"
                                    >
                                        {{
                                            row.target === null
                                                ? '—'
                                                : `${number(row.target)}%`
                                        }}
                                    </td>
                                    <td
                                        class="px-4 py-1.5 text-right tabular-nums"
                                    >
                                        {{ number(row.actual) }}%
                                        <span class="text-muted-foreground"
                                            >({{ row.count }})</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                </aside>
            </div>
        </template>
    </div>
</template>
