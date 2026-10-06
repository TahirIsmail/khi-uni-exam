<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, Eye, FileText, RotateCcw } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Outcome =
    | 'correct'
    | 'partly'
    | 'wrong'
    | 'blank'
    | 'examiner'
    | 'suggested';

defineProps<{
    examination: {
        id: number;
        title: string;
        totalMarks: number;
        passPercentage: number;
        negativeMarking: boolean;
    };
    rows: {
        number: number;
        reference: string;
        type: string;
        max: number;
        awarded: number | null;
        outcome: Outcome;
        given: string[] | null;
        key: string[] | null;
    }[];
    totals: {
        raw: number;
        deduction: number;
        total: number;
        percentage: number;
        passes: boolean;
        awaiting: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Conduct Exam', href: '/exams/conduct' }],
    },
});

const number = (value: number): string =>
    new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(
        value,
    );

const outcomes: Record<Outcome, { label: string; class: string }> = {
    correct: {
        label: 'Correct',
        class: 'border-green-600 text-green-700 dark:text-green-400',
    },
    partly: {
        label: 'Partly right',
        class: 'border-amber-500 text-amber-700 dark:text-amber-400',
    },
    wrong: { label: 'Wrong', class: 'border-red-500 text-red-600' },
    blank: { label: 'Not answered', class: 'text-muted-foreground' },
    examiner: {
        label: 'Marked by an examiner',
        class: 'text-muted-foreground',
    },
    suggested: {
        label: 'Suggested — examiner confirms',
        class: 'border-amber-500 text-amber-700 dark:text-amber-400',
    },
};
</script>

<template>
    <Head :title="`Preview result — ${examination.title}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`Preview finished — ${examination.title}`"
                description="Only you see this page. Nothing was saved, and candidates are never shown their marks here."
            />
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline" data-test="preview-again">
                    <Link :href="`/exams/${examination.id}/preview`"
                        ><RotateCcw /> Try the preview again</Link
                    >
                </Button>
                <Button as-child variant="outline">
                    <Link :href="`/exams/${examination.id}/paper/preview`"
                        ><FileText /> Preview paper</Link
                    >
                </Button>
                <Button as-child variant="ghost">
                    <Link :href="`/exams/${examination.id}/candidates`"
                        >Back to candidates</Link
                    >
                </Button>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[1fr_2fr]">
            <!-- 1. The screen a candidate gets once they submit (sit/Submitted). -->
            <section class="grid content-start gap-3">
                <h2 class="flex items-center gap-2 font-medium">
                    <Eye class="size-4" /> What the candidate sees
                </h2>
                <div
                    class="grid gap-3 rounded-xl border p-6 text-center shadow-xs"
                    data-test="candidate-sees"
                >
                    <CheckCircle2 class="mx-auto size-10 text-green-600" />
                    <p class="font-medium">
                        {{ examination.title }} — submitted
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Your answers have been received. You may close this
                        window.
                    </p>
                </div>
                <p class="text-muted-foreground text-xs">
                    Candidates see no marks when they submit. Their result comes
                    later, once marking is done and results are released.
                </p>
            </section>

            <!-- 2. How these answers would be marked: the real marking, not stored. -->
            <section class="grid content-start gap-3">
                <h2 class="font-medium">How these answers would be marked</h2>
                <div
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                    data-test="preview-totals"
                >
                    <div class="rounded-xl border px-4 py-3">
                        <div class="text-muted-foreground text-xs">Marks</div>
                        <div class="text-lg font-medium tabular-nums">
                            {{ number(totals.total) }} /
                            {{ number(examination.totalMarks) }}
                        </div>
                    </div>
                    <div class="rounded-xl border px-4 py-3">
                        <div class="text-muted-foreground text-xs">
                            Percentage
                        </div>
                        <div class="text-lg font-medium tabular-nums">
                            {{ number(totals.percentage) }}%
                        </div>
                        <div class="text-muted-foreground text-xs">
                            Pass at {{ number(examination.passPercentage) }}%
                        </div>
                    </div>
                    <div class="rounded-xl border px-4 py-3">
                        <div class="text-muted-foreground text-xs">
                            Negative marking
                        </div>
                        <div class="text-lg font-medium tabular-nums">
                            {{
                                examination.negativeMarking
                                    ? `− ${number(totals.deduction)}`
                                    : 'Off'
                            }}
                        </div>
                    </div>
                    <div class="rounded-xl border px-4 py-3">
                        <div class="text-muted-foreground text-xs">Result</div>
                        <div
                            class="text-lg font-medium"
                            :class="
                                totals.awaiting > 0
                                    ? ''
                                    : totals.passes
                                      ? 'text-green-700 dark:text-green-400'
                                      : 'text-red-600'
                            "
                            data-test="preview-verdict"
                        >
                            {{
                                totals.awaiting > 0
                                    ? 'Not final yet'
                                    : totals.passes
                                      ? 'Pass'
                                      : 'Fail'
                            }}
                        </div>
                        <div
                            v-if="totals.awaiting > 0"
                            class="text-muted-foreground text-xs"
                        >
                            {{ totals.awaiting }} answer{{
                                totals.awaiting === 1 ? '' : 's'
                            }}
                            for an examiner to mark
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto rounded-xl border shadow-xs">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left">
                            <tr>
                                <th class="px-3 py-2 font-medium">Question</th>
                                <th class="px-3 py-2 font-medium">Answered</th>
                                <th class="px-3 py-2 font-medium">Key</th>
                                <th class="px-3 py-2 text-right font-medium">
                                    Marks
                                </th>
                                <th class="px-3 py-2 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in rows"
                                :key="row.number"
                                class="border-t"
                                :data-row="row.number"
                            >
                                <td class="px-3 py-2">
                                    <div class="font-medium">
                                        Question {{ row.number }}
                                    </div>
                                    <div class="text-muted-foreground text-xs">
                                        {{ row.type }} · {{ row.reference }}
                                    </div>
                                </td>
                                <td class="px-3 py-2">
                                    {{
                                        row.given === null
                                            ? row.outcome === 'blank'
                                                ? '—'
                                                : 'Written answer'
                                            : row.given.join(', ') || '—'
                                    }}
                                </td>
                                <td class="px-3 py-2">
                                    {{ row.key?.join(', ') ?? '—' }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right whitespace-nowrap tabular-nums"
                                >
                                    {{
                                        row.awarded === null
                                            ? '—'
                                            : number(row.awarded)
                                    }}
                                    / {{ number(row.max) }}
                                </td>
                                <td class="px-3 py-2">
                                    <Badge
                                        variant="outline"
                                        :class="outcomes[row.outcome].class"
                                        >{{
                                            outcomes[row.outcome].label
                                        }}</Badge
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</template>
