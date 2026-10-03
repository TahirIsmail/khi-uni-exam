<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, History, Play, UserPlus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import CandidatePreview from '@/components/qbank/CandidatePreview.vue';
import QuestionJourney from '@/components/qbank/QuestionJourney.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as approvals } from '@/routes/approvals';
import { index as questions } from '@/routes/questions';
import { index as reviewQueue } from '@/routes/reviews';
import type {
    AssignmentRow,
    ChecklistItemInfo,
    PrehocDecisionInfo,
    PrehocRow,
    QuestionDraft,
    QuestionTypeInfo,
    ReviewRow,
    StoredVersion,
} from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Question bank', href: questions() }] },
});

const props = defineProps<{
    reference: string;
    questionId: number;
    version: StoredVersion;
    isAuthor: boolean;
    can: {
        review: boolean;
        prehoc: boolean;
        approve: boolean;
        assign: boolean;
    };
    reviewers: Partial<
        Record<
            'subject' | 'academic',
            { id: number; name: string; openLoad: number }[]
        >
    >;
    reviews: ReviewRow[];
    assignments: AssignmentRow[];
    prehoc: PrehocRow[];
    myAssignmentId: number | null;
    myStage: 'subject' | 'academic' | null;
    myStageLabel: string | null;
    subjectIn: number;
    academicIn: number;
    academicNeeded: number;
    singleLevel: boolean;
    checklistItems: ChecklistItemInfo[];
    decisions: PrehocDecisionInfo[];
    reviewsNeeded: number;
    autoActivate: boolean;
    reviewerAcceptStores: boolean;
    types: QuestionTypeInfo[];
    cognitiveLevels: { id: number; name: string; description: string | null }[];
    difficultyLevels: { id: number; name: string }[];
}>();

const type = computed(
    () =>
        props.types.find((row) => row.id === props.version.questionTypeId) ??
        null,
);

const draft = computed<QuestionDraft>(() => ({
    question_type_id: props.version.questionTypeId,
    course_id: props.version.courseId,
    node_id: props.version.nodeId,
    discipline_id: props.version.disciplineId,
    exam_type_id: props.version.examTypeId,
    intake_id: props.version.intakeId,
    vignette: props.version.vignette,
    stem: props.version.stem,
    lead_in: props.version.leadIn,
    explanation: props.version.explanation,
    settings: props.version.settings ?? {},
    marks: props.version.marks,
    negative_marks: props.version.negativeMarks,
    cognitive_level_id: props.version.cognitiveLevelId,
    difficulty_level_id: props.version.difficultyLevelId,
    options: props.version.options,
    items: props.version.items,
    answers: props.version.answers,
    rubric: props.version.rubric,
    references: props.version.references,
    tag_ids: props.version.tagIds,
}));

const consolidated = computed(
    () => props.prehoc.find((row) => row.isConsolidated) ?? null,
);
const authorProposal = computed(
    () => props.prehoc.find((row) => row.source === 'author') ?? null,
);
const base = computed(
    () => `/questions/${props.questionId}/versions/${props.version.id}`,
);

/** Both levels of review are in, so the approving authority is the next step. */
const awaitingApproval = computed(
    () =>
        props.subjectIn >= props.reviewsNeeded &&
        props.academicIn >= props.academicNeeded,
);

// ---- the reviewer's pre-hoc assessment ---------------------------------------------------------
const review = useForm<{
    assignment_id: number | null;
    outcome: 'reviewed' | 'changes_requested';
    decision_id: number | null;
    comments: string;
    checklist: { code: string; pass: boolean; note: string | null }[];
    cognitive_level_id: number | null;
    difficulty_level_id: number | null;
}>({
    assignment_id: props.myAssignmentId,
    outcome: 'reviewed',
    decision_id: null,
    comments: '',
    checklist: props.checklistItems.map((item) => ({
        code: item.code,
        pass: true,
        note: null,
    })),
    cognitive_level_id: props.version.cognitiveLevelId,
    difficulty_level_id: props.version.difficultyLevelId,
});

const chosenDecision = computed(
    () => props.decisions.find((row) => row.id === review.decision_id) ?? null,
);

const failedRequired = computed(() =>
    props.checklistItems
        .filter(
            (item) =>
                item.isRequired &&
                review.checklist.find((row) => row.code === item.code)?.pass ===
                    false,
        )
        .map((item) => item.text),
);

const failedCount = computed(
    () => review.checklist.filter((row) => !row.pass).length,
);

function submitReview(): void {
    review.post(`${base.value}/review`, { preserveScroll: true });
}

// ---- the approving authority's decision --------------------------------------------------------
const approval = useForm<{
    decision_id: number | null;
    cognitive_level_id: number | null;
    difficulty_level_id: number | null;
    reason: string;
}>({
    decision_id:
        props.decisions.find((row) => row.code === 'accept')?.id ?? null,
    cognitive_level_id: props.version.cognitiveLevelId,
    difficulty_level_id: props.version.difficultyLevelId,
    reason: '',
});

const approverDecision = computed(
    () =>
        props.decisions.find((row) => row.id === approval.decision_id) ?? null,
);

/** Anything but "store it" has to say why, so the author and the reviewers know. */
const reasonRequired = computed(
    () =>
        approverDecision.value !== null &&
        !['accept', 'retain'].includes(approverDecision.value.code),
);

const reasonLabel = computed(() => {
    switch (approverDecision.value?.code) {
        case 'revise':
            return 'What does the author have to change?';
        case 'review':
            return 'What should the reviewers look at again?';
        case 'remove':
            return 'Why can this question not be used?';
        default:
            return 'Note (needed only when the reviewers disagreed)';
    }
});

// ---- who is reviewing it -----------------------------------------------------------------------
const newReviewer = ref<number | null>(null);
const newStage = ref<'subject' | 'academic'>(
    !props.singleLevel && props.subjectIn >= props.reviewsNeeded
        ? 'academic'
        : 'subject',
);
const reviewersForStage = computed(() => props.reviewers[newStage.value] ?? []);

function assign(): void {
    if (newReviewer.value === null) {
        return;
    }
    router.post(
        `${base.value}/reviewers`,
        { reviewer_id: newReviewer.value, stage: newStage.value },
        { preserveScroll: true, onSuccess: () => (newReviewer.value = null) },
    );
}

function cancelAssignment(id: number): void {
    const reason = window.prompt(
        'Why are you taking this review back? The reviewer will see it.',
    );
    if (reason === null || reason.trim().length < 5) {
        return;
    }
    router.delete(`${base.value}/reviewers/${id}`, {
        data: { reason },
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`Review ${reference}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`${reference} — version ${version.versionNo}`"
                :description="
                    isAuthor
                        ? 'Your question, as its reviewers see it. Their comments appear below once they have reviewed it.'
                        : 'Read the question as a candidate would see it, then record your pre-hoc assessment.'
                "
            />
            <div class="flex flex-wrap items-center gap-2">
                <Button as-child size="sm" variant="ghost">
                    <Link :href="`/questions/${questionId}`"
                        ><History /> History</Link
                    >
                </Button>
                <Button v-if="can.review" as-child size="sm" variant="outline">
                    <Link :href="reviewQueue()">My reviews</Link>
                </Button>
                <Button
                    v-else-if="can.approve"
                    as-child
                    size="sm"
                    variant="outline"
                >
                    <Link :href="approvals()">Approvals</Link>
                </Button>
            </div>
        </div>

        <QuestionJourney
            :status="version.status"
            :status-label="version.statusLabel"
            :awaiting-approval="awaitingApproval"
        />

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="flex flex-col gap-6">
                <CandidatePreview
                    :draft="draft"
                    :type="type"
                    :show-answers="true"
                />

                <div
                    v-if="version.explanation"
                    class="rounded-xl border p-4 text-sm shadow-xs"
                >
                    <h3 class="mb-2 font-medium">Why that is the answer</h3>
                    <!-- eslint-disable vue/no-v-html -- sanitised on the server before storing -->
                    <div
                        class="prose-sm max-w-none"
                        v-html="version.explanation"
                    />
                </div>

                <div class="rounded-xl border p-4 text-sm shadow-xs">
                    <h3 class="mb-3 font-medium">
                        What the author said about it
                    </h3>
                    <dl class="grid grid-cols-2 gap-2">
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Cognitive level
                            </dt>
                            <dd>{{ authorProposal?.cognitive ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Difficulty level
                            </dt>
                            <dd>{{ authorProposal?.difficulty ?? '—' }}</dd>
                        </div>
                    </dl>
                    <p class="text-muted-foreground mt-2 text-xs">
                        A reviewer's judgement takes precedence; both are kept.
                    </p>
                </div>
            </div>

            <div class="flex flex-col gap-6">
                <!-- The reviewer's pre-hoc assessment: cognitive level, difficulty level, decision. -->
                <form
                    v-if="can.review"
                    class="grid gap-4 rounded-xl border p-4 shadow-xs"
                    data-test="review-form"
                    @submit.prevent="submitReview"
                >
                    <div>
                        <h3 class="font-medium" data-test="my-stage">
                            Pre-hoc assessment
                            <span
                                v-if="myStageLabel"
                                class="text-muted-foreground font-normal"
                                >— {{ myStageLabel }}</span
                            >
                        </h3>
                        <p class="text-muted-foreground text-xs">
                            Before the question is used: how hard it is, what it
                            tests, and what should happen to it.
                        </p>
                    </div>

                    <div v-if="can.prehoc" class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="cognitive">Cognitive level</Label>
                            <select
                                id="cognitive"
                                v-model="review.cognitive_level_id"
                                class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                data-test="prehoc-cognitive"
                            >
                                <option :value="null">—</option>
                                <option
                                    v-for="level in cognitiveLevels"
                                    :key="level.id"
                                    :value="level.id"
                                >
                                    {{ level.name }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="difficulty">Difficulty level</Label>
                            <select
                                id="difficulty"
                                v-model="review.difficulty_level_id"
                                class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                data-test="prehoc-difficulty"
                            >
                                <option :value="null">—</option>
                                <option
                                    v-for="level in difficultyLevels"
                                    :key="level.id"
                                    :value="level.id"
                                >
                                    {{ level.name }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="decision">Question quality</Label>
                        <select
                            id="decision"
                            v-model="review.decision_id"
                            class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                            data-test="decision"
                        >
                            <option :value="null">Choose…</option>
                            <option
                                v-for="row in decisions"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.name }}
                            </option>
                        </select>
                        <p
                            v-if="chosenDecision?.description"
                            class="text-muted-foreground text-xs"
                        >
                            {{ chosenDecision.description }}
                        </p>
                        <p
                            v-if="
                                reviewerAcceptStores &&
                                (myStage === 'academic' ||
                                    academicNeeded === 0) &&
                                chosenDecision?.isAccept
                            "
                            class="rounded-md bg-emerald-50 px-2 py-1 text-xs text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200"
                            data-test="accept-stores-hint"
                        >
                            When every earlier reviewer accepted it too, this
                            stores the question in the QBank straight away, with
                            no separate approval.
                        </p>
                        <p
                            v-if="review.errors.decision_id"
                            class="text-destructive text-sm"
                        >
                            {{ review.errors.decision_id }}
                        </p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="comments">Comments for the author</Label>
                        <textarea
                            id="comments"
                            v-model="review.comments"
                            class="border-input bg-background min-h-24 rounded-md border p-2 text-sm"
                            maxlength="5000"
                            data-test="comments"
                            placeholder="What is good, what needs changing, and why."
                        />
                        <p
                            v-if="review.errors.comments"
                            class="text-destructive text-sm"
                            data-test="comments-error"
                        >
                            {{ review.errors.comments }}
                        </p>
                    </div>

                    <!-- The item-writing rules, out of the way until they are wanted. -->
                    <details class="rounded-lg border px-3 py-2">
                        <summary
                            class="cursor-pointer text-sm font-medium"
                            data-test="checklist-toggle"
                        >
                            Item-writing checklist
                            <span
                                v-if="failedCount > 0"
                                class="text-destructive"
                                >({{ failedCount }} marked wrong)</span
                            >
                            <span
                                v-else
                                class="text-muted-foreground font-normal"
                                >(optional)</span
                            >
                        </summary>
                        <fieldset class="mt-3 grid gap-2">
                            <div
                                v-for="(item, i) in checklistItems"
                                :key="item.code"
                                class="grid gap-1 border-b pb-2 last:border-0"
                                :data-checklist="item.code"
                            >
                                <div
                                    class="flex items-start justify-between gap-2"
                                >
                                    <span class="text-sm"
                                        >{{ item.text
                                        }}<Badge
                                            v-if="item.isRequired"
                                            variant="outline"
                                            class="ml-1"
                                            >required</Badge
                                        ></span
                                    >
                                    <div class="flex shrink-0 gap-1">
                                        <Button
                                            type="button"
                                            size="sm"
                                            :variant="
                                                review.checklist[i].pass
                                                    ? 'default'
                                                    : 'outline'
                                            "
                                            :data-pass="item.code"
                                            @click="
                                                review.checklist[i].pass = true
                                            "
                                            ><Check
                                        /></Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            :variant="
                                                review.checklist[i].pass
                                                    ? 'outline'
                                                    : 'destructive'
                                            "
                                            :data-fail="item.code"
                                            @click="
                                                review.checklist[i].pass = false
                                            "
                                            ><X
                                        /></Button>
                                    </div>
                                </div>
                                <p
                                    v-if="item.guidance"
                                    class="text-muted-foreground text-xs"
                                >
                                    {{ item.guidance }}
                                </p>
                                <Input
                                    v-if="!review.checklist[i].pass"
                                    v-model="review.checklist[i].note as string"
                                    placeholder="What is wrong with it?"
                                    maxlength="500"
                                />
                            </div>
                            <p
                                v-if="review.errors.checklist"
                                class="text-destructive text-sm"
                            >
                                {{ review.errors.checklist }}
                            </p>
                        </fieldset>
                    </details>

                    <p
                        v-if="failedRequired.length > 0"
                        class="border-destructive/40 bg-destructive/5 text-destructive rounded-lg border p-3 text-xs"
                        data-test="failed-required"
                    >
                        A required rule fails, so this question cannot be
                        approved as it is. Choose "Revise" and say what the
                        author has to change.
                    </p>

                    <div>
                        <Button
                            type="submit"
                            :disabled="review.processing"
                            data-test="submit-review"
                        >
                            <Check /> Submit review
                        </Button>
                        <p class="text-muted-foreground mt-2 text-xs">
                            Choosing "Revise" sends the question back to its
                            author. A submitted review cannot be changed
                            afterwards.
                        </p>
                    </div>
                </form>

                <!-- The approving authority's decision: one choice, one button. -->
                <form
                    v-if="can.approve && version.status === 'under_review'"
                    class="grid gap-4 rounded-xl border p-4 shadow-xs"
                    data-test="approval-form"
                    @submit.prevent="
                        approval.post(`${base}/decide`, {
                            preserveScroll: true,
                        })
                    "
                >
                    <div>
                        <h3 class="font-medium">Your decision</h3>
                        <p class="text-muted-foreground text-xs">
                            The levels you settle on are the ones the question
                            keeps in the QBank.
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="c-cognitive">Cognitive level</Label>
                            <select
                                id="c-cognitive"
                                v-model="approval.cognitive_level_id"
                                class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                data-test="approve-cognitive"
                            >
                                <option :value="null">—</option>
                                <option
                                    v-for="level in cognitiveLevels"
                                    :key="level.id"
                                    :value="level.id"
                                >
                                    {{ level.name }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="c-difficulty">Difficulty level</Label>
                            <select
                                id="c-difficulty"
                                v-model="approval.difficulty_level_id"
                                class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                data-test="approve-difficulty"
                            >
                                <option :value="null">—</option>
                                <option
                                    v-for="level in difficultyLevels"
                                    :key="level.id"
                                    :value="level.id"
                                >
                                    {{ level.name }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="c-decision">Question quality</Label>
                        <select
                            id="c-decision"
                            v-model="approval.decision_id"
                            class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                            data-test="approve-decision"
                        >
                            <option
                                v-for="row in decisions"
                                :key="row.id"
                                :value="row.id"
                            >
                                {{ row.name }}
                            </option>
                        </select>
                        <p
                            v-if="approverDecision?.description"
                            class="text-muted-foreground text-xs"
                        >
                            {{ approverDecision.description }}
                        </p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="c-reason">{{ reasonLabel }}</Label>
                        <Input
                            id="c-reason"
                            v-model="approval.reason"
                            maxlength="500"
                            :required="reasonRequired"
                            data-test="approve-reason"
                        />
                        <p
                            v-for="(message, field) in approval.errors"
                            :key="field"
                            class="text-destructive text-sm"
                            data-test="approve-error"
                        >
                            {{ message }}
                        </p>
                    </div>

                    <Button
                        type="submit"
                        class="justify-self-start"
                        :disabled="approval.processing"
                        data-test="approve"
                    >
                        <Check />
                        {{
                            approverDecision !== null && !reasonRequired
                                ? autoActivate
                                    ? 'Store in the QBank and put into use'
                                    : 'Store in the QBank'
                                : 'Save decision'
                        }}
                    </Button>
                </form>

                <div
                    v-if="can.approve && version.status === 'approved'"
                    class="grid gap-3 rounded-xl border p-4 shadow-xs"
                >
                    <h3 class="font-medium">Approved</h3>
                    <p class="text-muted-foreground text-sm">
                        This question is approved but not in use yet, so no
                        examination can draw on it.
                    </p>
                    <Button
                        class="justify-self-start"
                        data-test="activate"
                        @click="
                            router.post(
                                `${base}/activate`,
                                {},
                                { preserveScroll: true },
                            )
                        "
                    >
                        <Play /> Put it into use
                    </Button>
                </div>

                <!-- What the reviewers said. -->
                <div class="grid gap-3 rounded-xl border p-4 shadow-xs">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-medium">Reviews</h3>
                        <Badge variant="outline" data-test="subject-count"
                            >{{
                                singleLevel
                                    ? 'Reviewed'
                                    : 'Department / Subject'
                            }}
                            {{ subjectIn }} of {{ reviewsNeeded }}</Badge
                        >
                        <Badge
                            v-if="!singleLevel"
                            variant="outline"
                            data-test="academic-count"
                            >QBank / Academic {{ academicIn }} of 1</Badge
                        >
                    </div>
                    <article
                        v-for="row in reviews"
                        :key="row.id"
                        class="grid gap-2 border-b pb-3 text-sm last:border-0 last:pb-0"
                        :data-review="row.id"
                    >
                        <header class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ row.reviewer }}</span>
                            <Badge variant="outline">{{
                                row.stageLabel
                            }}</Badge>
                            <Badge v-if="row.isMe" variant="outline">you</Badge>
                            <Badge
                                :variant="
                                    row.outcome === 'changes_requested'
                                        ? 'destructive'
                                        : 'secondary'
                                "
                                >{{ row.decision ?? 'reviewed' }}</Badge
                            >
                            <span class="text-muted-foreground text-xs">{{
                                new Date(row.submittedAt).toLocaleString()
                            }}</span>
                        </header>
                        <p v-if="row.comments" class="whitespace-pre-line">
                            {{ row.comments }}
                        </p>
                        <ul
                            v-if="row.failedRequired.length > 0"
                            class="text-destructive list-disc pl-4 text-xs"
                        >
                            <li
                                v-for="(text, i) in row.failedRequired"
                                :key="i"
                            >
                                {{ text }}
                            </li>
                        </ul>
                        <p
                            v-if="row.prehoc"
                            class="text-muted-foreground text-xs"
                        >
                            {{ row.prehoc.cognitive ?? '—' }} ·
                            {{ row.prehoc.difficulty ?? '—' }}
                        </p>
                    </article>
                    <p
                        v-if="reviews.length === 0"
                        class="text-muted-foreground text-sm"
                    >
                        Nobody has reviewed this version yet.
                    </p>

                    <div
                        v-if="consolidated"
                        class="rounded-lg border p-3 text-sm"
                        data-test="consolidated"
                    >
                        <h4 class="font-medium">Settled on approval</h4>
                        <p class="text-muted-foreground">
                            {{ consolidated.cognitive ?? '—' }} ·
                            {{ consolidated.difficulty ?? '—' }}
                        </p>
                        <p v-if="consolidated.reason" class="mt-1">
                            {{ consolidated.reason }}
                        </p>
                    </div>
                </div>

                <!-- Who is reviewing it — only wanted when somebody asks. -->
                <details class="rounded-xl border px-4 py-3 shadow-xs">
                    <summary
                        class="cursor-pointer font-medium"
                        data-test="reviewers-toggle"
                    >
                        Reviewers
                        <span class="text-muted-foreground font-normal"
                            >({{ assignments.length }})</span
                        >
                    </summary>
                    <ul class="mt-3 grid gap-2 text-sm">
                        <li
                            v-for="row in assignments"
                            :key="row.id"
                            class="flex flex-wrap items-center gap-2"
                            :data-assignment="row.id"
                        >
                            <span>{{ row.reviewer }}</span>
                            <Badge variant="outline">{{
                                row.stageLabel
                            }}</Badge>
                            <Badge v-if="row.isMe" variant="outline">you</Badge>
                            <Badge
                                :variant="
                                    row.status === 'submitted'
                                        ? 'secondary'
                                        : row.isOverdue
                                          ? 'destructive'
                                          : 'outline'
                                "
                                >{{
                                    row.status === 'open' && row.isOverdue
                                        ? 'late'
                                        : row.status
                                }}</Badge
                            >
                            <span
                                v-if="row.dueAt && row.status === 'open'"
                                class="text-muted-foreground text-xs"
                                >due
                                {{ new Date(row.dueAt).toLocaleDateString() }}
                            </span>
                            <span
                                v-if="row.wasAutomatic"
                                class="text-muted-foreground text-xs"
                                >· assigned automatically</span
                            >
                            <Button
                                v-if="can.assign && row.status === 'open'"
                                size="sm"
                                variant="ghost"
                                :data-cancel="row.id"
                                @click="cancelAssignment(row.id)"
                                >Take it back</Button
                            >
                        </li>
                        <li
                            v-if="assignments.length === 0"
                            class="text-muted-foreground"
                        >
                            Nobody has been asked yet.
                        </li>
                    </ul>

                    <div
                        v-if="can.assign"
                        class="mt-3 flex flex-wrap items-end gap-2"
                    >
                        <div v-if="!singleLevel" class="grid gap-1.5">
                            <Label for="stage">Level</Label>
                            <select
                                id="stage"
                                v-model="newStage"
                                class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                data-test="stage"
                                @change="newReviewer = null"
                            >
                                <option value="subject">
                                    Department / Subject
                                </option>
                                <option value="academic">
                                    QBank / Academic
                                </option>
                            </select>
                        </div>
                        <div
                            class="grid flex-1 gap-1.5"
                            style="min-width: 12rem"
                        >
                            <Label for="reviewer"
                                >Ask somebody to review it</Label
                            >
                            <select
                                id="reviewer"
                                v-model="newReviewer"
                                class="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                data-test="reviewer"
                            >
                                <option :value="null">Choose…</option>
                                <option
                                    v-for="row in reviewersForStage"
                                    :key="row.id"
                                    :value="row.id"
                                >
                                    {{ row.name }} ({{ row.openLoad }} open)
                                </option>
                            </select>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="newReviewer === null"
                            data-test="assign"
                            @click="assign"
                        >
                            <UserPlus /> Ask
                        </Button>
                    </div>
                </details>
            </div>
        </div>
    </div>
</template>
