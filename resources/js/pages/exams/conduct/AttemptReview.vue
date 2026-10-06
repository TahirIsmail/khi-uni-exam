<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Check, Flag, Printer, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import conduct from '@/routes/conduct';
import type { ExaminationDetail } from '@/types';

type Outcome = 'correct' | 'wrong' | 'blank' | 'partial' | 'pending';
type ReviewItem = {
    id: number;
    position: number;
    vignette: string | null;
    stem: string;
    leadIn: string | null;
    options: {
        letter: string;
        body: string;
        isCorrect: boolean;
        chosen: boolean;
    }[];
    answerText: string | null;
    flagged: boolean;
    marks: number;
    awarded: number | null;
    outcome: Outcome;
};

const props = defineProps<{
    examination: ExaminationDetail;
    candidate: {
        candidateNo: string;
        name: string;
        rollNo: string | null;
        status: string;
        startedAt: string | null;
        submittedAt: string | null;
    };
    score: {
        awarded: number;
        total: number;
        percent: number;
        passMarks: number;
        passed: boolean;
        pending: number;
    };
    counts: Record<Outcome, number>;
    items: ReviewItem[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Conduct Exam', href: conduct.index() }] },
});

// Narrow the list to one kind of answer — the wrong ones, say.
const show = ref<Outcome | 'all'>('all');
const shown = computed(() =>
    show.value === 'all'
        ? props.items
        : props.items.filter((item) => item.outcome === show.value),
);

const outcomeLabel: Record<Outcome, string> = {
    correct: 'Right',
    wrong: 'Wrong',
    blank: 'Not answered',
    partial: 'Part marks',
    pending: 'Awaiting a marker',
};
const outcomeStyle: Record<
    Outcome,
    'default' | 'destructive' | 'secondary' | 'outline'
> = {
    correct: 'default',
    wrong: 'destructive',
    blank: 'secondary',
    partial: 'outline',
    pending: 'outline',
};

function printPage(): void {
    window.print();
}
</script>

<template>
    <Head :title="`Answers — ${candidate.candidateNo}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`${candidate.name} — ${candidate.candidateNo}`"
                :description="`${examination.title} · ${candidate.status}${candidate.submittedAt ? ` · submitted ${candidate.submittedAt}` : ''}`"
            />
            <div class="flex gap-2 print:hidden">
                <Button variant="outline" as-child>
                    <Link :href="conduct.monitor(examination.id)"
                        >Back to the monitor</Link
                    >
                </Button>
                <Button variant="outline" @click="printPage"
                    ><Printer /> Print</Button
                >
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-4" data-test="review-summary">
            <div class="rounded-xl border p-3 shadow-xs">
                <div class="text-2xl font-semibold tabular-nums">
                    {{ score.awarded }} / {{ score.total }}
                </div>
                <div class="text-muted-foreground text-xs">
                    {{ score.percent }}% · pass at {{ score.passMarks }}
                </div>
            </div>
            <div class="rounded-xl border p-3 shadow-xs">
                <Badge
                    v-if="score.pending > 0"
                    variant="outline"
                    class="text-sm"
                    >Result pending</Badge
                >
                <Badge
                    v-else-if="score.passed"
                    class="bg-emerald-600 text-sm text-white"
                    data-test="review-passed"
                    >Passed</Badge
                >
                <Badge
                    v-else
                    variant="destructive"
                    class="text-sm"
                    data-test="review-failed"
                    >Not passed</Badge
                >
                <div class="text-muted-foreground mt-1 text-xs">
                    {{
                        candidate.startedAt
                            ? `Started ${candidate.startedAt}`
                            : ''
                    }}
                </div>
            </div>
            <button
                v-for="kind in ['correct', 'wrong', 'blank'] as const"
                :key="kind"
                type="button"
                class="hover:bg-accent rounded-xl border p-3 text-left shadow-xs print:hidden"
                :class="show === kind ? 'border-primary bg-accent' : ''"
                @click="show = show === kind ? 'all' : kind"
            >
                <div class="text-2xl font-semibold tabular-nums">
                    {{ counts[kind] }}
                </div>
                <div class="text-muted-foreground text-xs">
                    {{ outcomeLabel[kind] }}
                    {{
                        show === kind
                            ? '· showing only these'
                            : '· show only these'
                    }}
                </div>
            </button>
        </div>

        <article
            v-for="item in shown"
            :key="item.id"
            class="grid break-inside-avoid gap-3 rounded-xl border p-4 shadow-xs"
            :data-item="item.id"
        >
            <header class="flex flex-wrap items-center gap-2">
                <span class="font-medium">Question {{ item.position }}</span>
                <Badge :variant="outcomeStyle[item.outcome]">{{
                    outcomeLabel[item.outcome]
                }}</Badge>
                <span class="text-muted-foreground text-xs tabular-nums"
                    >{{ item.awarded ?? '—' }} / {{ item.marks }} marks</span
                >
                <Flag
                    v-if="item.flagged"
                    class="size-4 text-amber-600"
                    aria-label="Marked for review by the candidate"
                />
            </header>

            <div
                v-if="item.vignette"
                class="text-muted-foreground text-sm [&_img]:max-h-64"
                v-html="item.vignette"
            />
            <div
                class="text-sm leading-relaxed [&_img]:max-h-64"
                v-html="item.stem"
            />
            <div
                v-if="item.leadIn"
                class="text-sm font-medium"
                v-html="item.leadIn"
            />

            <ul class="grid gap-1.5">
                <li
                    v-for="option in item.options"
                    :key="option.letter"
                    class="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm"
                    :class="{
                        'border-emerald-400 bg-emerald-50 dark:bg-emerald-950':
                            option.isCorrect,
                        'border-red-400 bg-red-50 dark:bg-red-950':
                            option.chosen && !option.isCorrect,
                    }"
                >
                    <span class="font-mono font-medium">{{
                        option.letter
                    }}</span>
                    <span class="flex-1" v-html="option.body" />
                    <span
                        v-if="option.chosen"
                        class="flex items-center gap-1 text-xs font-medium"
                        :class="
                            option.isCorrect
                                ? 'text-emerald-700 dark:text-emerald-300'
                                : 'text-red-700 dark:text-red-300'
                        "
                    >
                        <component
                            :is="option.isCorrect ? Check : X"
                            class="size-3.5"
                        />
                        Candidate's answer
                    </span>
                    <span
                        v-else-if="option.isCorrect"
                        class="flex items-center gap-1 text-xs font-medium text-emerald-700 dark:text-emerald-300"
                    >
                        <Check class="size-3.5" /> Right answer
                    </span>
                </li>
            </ul>
            <p
                v-if="item.answerText"
                class="bg-muted rounded-lg p-3 text-sm whitespace-pre-wrap"
            >
                {{ item.answerText }}
            </p>
        </article>

        <p v-if="shown.length === 0" class="text-muted-foreground text-sm">
            No questions of this kind.
        </p>
    </div>
</template>
