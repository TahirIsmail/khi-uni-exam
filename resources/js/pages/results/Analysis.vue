<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Download, Printer } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import results from '@/routes/results';
import type {
    AnalysisBand,
    AnalysisThresholds,
    AnalyticsAbilities,
    ExamStatistics,
    ItemAnalysisRow,
    PosthocDecisionOption,
    Reliability,
    TosMix,
    TosRow,
} from '@/types';

/*
 * Post-hoc analysis in KMU's categories (IQQUIK Phase I): overall examination statistics,
 * reliability (KR-20 and Cronbach's alpha), item analysis and distractor analysis, each with KMU's
 * own "in plain terms" explanation, and the decision that goes back to the question bank.
 */
const props = defineProps<{
    examination: {
        id: number;
        reference: string;
        title: string;
        course: string | null;
    };
    statistics: ExamStatistics;
    items: ItemAnalysisRow[];
    decisions: Record<number, { code: string; name: string }>;
    thresholds: AnalysisThresholds;
    reliability: Reliability;
    tos: { rows: TosRow[]; mixes: TosMix[] };
    decisionTypes: PosthocDecisionOption[];
    can: AnalyticsAbilities;
}>();

function runAnalysis(): void {
    router.post(
        results.analysis.run(props.examination.id).url,
        {},
        { preserveScroll: true },
    );
}

const deciding = ref<number | null>(null);
const decideForm = useForm<{ decision: string; reason: string }>({
    decision: props.decisionTypes[0]?.code ?? '',
    reason: '',
});

function startDecide(item: ItemAnalysisRow): void {
    deciding.value = item.versionId;
    decideForm.reset();
}
function submitDecide(item: ItemAnalysisRow): void {
    decideForm.post(
        results.questions.decide([props.examination.id, item.versionId]).url,
        {
            preserveScroll: true,
            onSuccess: () => {
                deciding.value = null;
                decideForm.reset();
            },
        },
    );
}

function printPage(): void {
    window.print();
}

function percent(value: number | null): string {
    return value === null ? '—' : `${Math.round(value * 100)}%`;
}
function number(value: number | null, digits = 2): string {
    return value === null ? '—' : String(Number(value.toFixed(digits)));
}

const bandClass: Record<AnalysisBand['key'], string> = {
    good: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    ok: 'bg-sky-50 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
    warn: 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    bad: 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200',
};

// --- overall statistics ---------------------------------------------------------------------
const statCards = computed(() => [
    { label: 'Number of students', value: String(props.statistics.students) },
    { label: 'Total marks', value: number(props.statistics.totalMarks) },
    { label: 'Mean', value: number(props.statistics.mean) },
    { label: 'Median', value: number(props.statistics.median) },
    { label: 'Standard deviation', value: number(props.statistics.sd) },
    { label: 'Minimum score', value: number(props.statistics.min) },
    { label: 'Maximum score', value: number(props.statistics.max) },
    {
        label: 'Pass percentage',
        value:
            props.statistics.passPercent === null
                ? '—'
                : `${number(props.statistics.passPercent, 1)}%`,
    },
    {
        label: 'Fail percentage',
        value:
            props.statistics.failPercent === null
                ? '—'
                : `${number(props.statistics.failPercent, 1)}%`,
    },
]);

// --- item analysis: which rows to show --------------------------------------------------------
type Filter = 'all' | 'flags' | 'nfd' | 'undecided';
const filter = ref<Filter>('all');
const filters: { key: Filter; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'flags', label: 'Red flags' },
    { key: 'nfd', label: 'Non-functional distractors' },
    { key: 'undecided', label: 'No decision yet' },
];

const hasRedFlag = (item: ItemAnalysisRow): boolean =>
    item.discriminationBand?.key === 'bad' ||
    (item.distractorAnalysis?.possibleMiskey.length ?? 0) > 0 ||
    (item.distractorAnalysis?.defective.length ?? 0) > 0;
const hasNfd = (item: ItemAnalysisRow): boolean =>
    (item.distractorAnalysis?.nonFunctional.length ?? 0) > 0;

const counts = computed(() => ({
    all: props.items.length,
    flags: props.items.filter(hasRedFlag).length,
    nfd: props.items.filter(hasNfd).length,
    undecided: props.items.filter((item) => !props.decisions[item.versionId])
        .length,
}));

const shown = computed(() =>
    props.items.filter((item) =>
        filter.value === 'flags'
            ? hasRedFlag(item)
            : filter.value === 'nfd'
              ? hasNfd(item)
              : filter.value === 'undecided'
                ? !props.decisions[item.versionId]
                : true,
    ),
);

const open = ref<number | null>(null);

function optionClass(
    item: ItemAnalysisRow,
    label: string,
    correct: boolean,
): string {
    if (correct) {
        return 'font-semibold text-emerald-700 dark:text-emerald-300';
    }
    const analysis = item.distractorAnalysis;
    if (
        analysis?.possibleMiskey.includes(label) ||
        analysis?.defective.includes(label)
    ) {
        return 'font-semibold text-red-700 dark:text-red-300';
    }
    if (analysis?.nonFunctional.includes(label)) {
        return 'text-muted-foreground line-through decoration-dotted';
    }

    return '';
}

const nfdPercent = computed(() =>
    Math.round(props.thresholds.functional_distractor_share * 100),
);
</script>

<template>
    <Head :title="`Analysis — ${examination.title}`" />

    <div class="flex flex-col gap-6 p-4 print:p-0">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`Analysis — ${examination.title}`"
                :description="`${examination.reference}${examination.course ? ' · ' + examination.course : ''}`"
            />
            <div class="flex flex-wrap gap-2 print:hidden">
                <Button as-child size="sm" variant="outline">
                    <a
                        :href="results.analysis.export(examination.id).url"
                        data-test="export-analysis"
                        ><Download /> Excel</a
                    >
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    data-test="print-analysis"
                    @click="printPage"
                >
                    <Printer /> Print / PDF
                </Button>
                <Button
                    v-if="can.run"
                    size="sm"
                    data-test="run-analysis"
                    @click="runAnalysis"
                    >Run analysis</Button
                >
            </div>
        </div>

        <!-- Overall examination statistics -->
        <section class="grid gap-3" data-test="exam-statistics">
            <h2 class="text-sm font-medium">Overall examination statistics</h2>
            <div
                class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-9"
            >
                <div
                    v-for="card in statCards"
                    :key="card.label"
                    class="rounded-xl border p-3 shadow-xs"
                >
                    <div
                        class="text-muted-foreground text-[11px] font-medium tracking-wide uppercase"
                    >
                        {{ card.label }}
                    </div>
                    <div class="mt-1 text-xl font-semibold tabular-nums">
                        {{ card.value }}
                    </div>
                </div>
            </div>
            <p class="text-muted-foreground text-xs">
                Pass mark {{ number(statistics.passMark, 1) }}% ·
                {{ statistics.passed }} passed, {{ statistics.failed }} failed.
                <template v-if="statistics.pending > 0">
                    {{ statistics.pending }} attempt(s) still being marked are
                    not counted yet.
                </template>
            </p>
        </section>

        <!-- Reliability -->
        <section
            class="grid gap-3 rounded-xl border p-4 shadow-xs"
            data-test="reliability"
        >
            <h2 class="text-sm font-medium">Reliability analysis</h2>
            <template v-if="reliability.alpha !== null">
                <div class="flex flex-wrap gap-6">
                    <div>
                        <div class="text-muted-foreground text-xs">KR-20</div>
                        <div
                            class="flex items-center gap-2 text-xl font-semibold tabular-nums"
                            data-test="kr20"
                        >
                            {{
                                reliability.kr20 === null
                                    ? '—'
                                    : number(reliability.kr20)
                            }}
                            <span
                                v-if="
                                    reliability.kr20 !== null &&
                                    reliability.band
                                "
                                class="rounded px-2 py-0.5 text-xs font-medium"
                                :class="bandClass[reliability.band.key]"
                                >{{ reliability.band.label }}</span
                            >
                        </div>
                        <div
                            v-if="reliability.kr20 === null"
                            class="text-muted-foreground max-w-xs text-xs"
                        >
                            Not used here: some questions were given part marks,
                            and KR-20 is for papers marked purely right or
                            wrong.
                        </div>
                    </div>
                    <div>
                        <div class="text-muted-foreground text-xs">
                            Cronbach's alpha
                        </div>
                        <div
                            class="flex items-center gap-2 text-xl font-semibold tabular-nums"
                            data-test="alpha"
                        >
                            {{ number(reliability.alpha) }}
                            <span
                                v-if="reliability.band"
                                class="rounded px-2 py-0.5 text-xs font-medium"
                                :class="bandClass[reliability.band.key]"
                                >{{ reliability.band.label }}</span
                            >
                        </div>
                    </div>
                    <div class="text-muted-foreground self-end text-xs">
                        {{ reliability.candidates }} candidates
                    </div>
                </div>
            </template>
            <p v-else class="text-sm">
                Not enough candidates yet ({{ reliability.candidates }}, at
                least {{ thresholds.min_candidates }} needed) for a meaningful
                figure.
            </p>
            <p class="text-muted-foreground text-xs">
                <strong>In plain terms:</strong> both are consistency scores for
                the whole exam, from 0 to 1. They answer: “if we gave a similar
                exam to the same students again, would the results come out
                about the same?” A score above roughly
                {{ thresholds.reliability.acceptable_from }} is generally
                acceptable for a classroom exam; higher is more consistent.
                KR-20 is used for exams marked purely right/wrong (e.g. MCQs).
            </p>
        </section>

        <section v-if="tos.rows.length > 0" class="grid gap-2 print:hidden">
            <h2 class="text-sm font-medium">
                Compliance with the table of specification
            </h2>
            <div class="overflow-x-auto rounded-xl border shadow-xs">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Row</th>
                            <th class="px-3 py-2 font-medium">Planned</th>
                            <th class="px-3 py-2 font-medium">Delivered</th>
                            <th class="px-3 py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, index) in tos.rows"
                            :key="index"
                            class="border-t"
                        >
                            <td class="px-3 py-2">Row {{ index + 1 }}</td>
                            <td class="px-3 py-2 tabular-nums">
                                {{ row.plannedCount }} questions /
                                {{ row.plannedMarks }} marks
                            </td>
                            <td class="px-3 py-2 tabular-nums">
                                {{ row.deliveredCount }} questions /
                                {{ row.deliveredMarks }} marks
                            </td>
                            <td class="px-3 py-2">
                                <Badge
                                    :variant="
                                        row.compliant
                                            ? 'default'
                                            : 'destructive'
                                    "
                                    >{{
                                        row.compliant ? 'Compliant' : 'Mismatch'
                                    }}</Badge
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-if="tos.mixes.length > 0"
                class="flex flex-wrap gap-3 text-sm"
            >
                <span
                    v-for="(mix, index) in tos.mixes"
                    :key="index"
                    class="text-muted-foreground"
                >
                    {{ mix.dimension }} level {{ mix.levelId }}: planned
                    {{ mix.plannedPercent }}%, delivered
                    {{ mix.deliveredPercent }}%
                </span>
            </div>
        </section>

        <!-- Item and distractor analysis -->
        <section class="grid gap-2" data-test="item-analysis">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-medium">
                    Item analysis and distractor analysis
                </h2>
                <div class="flex flex-wrap gap-1.5 print:hidden">
                    <button
                        v-for="option in filters"
                        :key="option.key"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs font-medium"
                        :class="
                            filter === option.key
                                ? 'bg-primary text-primary-foreground border-primary'
                                : 'bg-background'
                        "
                        :data-test="`filter-${option.key}`"
                        @click="filter = option.key"
                    >
                        {{ option.label }} ({{ counts[option.key] }})
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border shadow-xs">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Question No.</th>
                            <th class="px-3 py-2 font-medium">Students</th>
                            <th class="px-3 py-2 font-medium">
                                Difficulty Index
                            </th>
                            <th class="px-3 py-2 font-medium">
                                Discrimination Index
                            </th>
                            <th class="px-3 py-2 font-medium">Options</th>
                            <th class="px-3 py-2 font-medium">Distractors</th>
                            <th class="px-3 py-2 font-medium">
                                Status / Decision
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="item in shown" :key="item.paperItemId">
                            <tr
                                class="hover:bg-muted/30 cursor-pointer border-t align-top"
                                :data-item="item.paperItemId"
                                @click="
                                    open =
                                        open === item.paperItemId
                                            ? null
                                            : item.paperItemId
                                "
                            >
                                <td class="px-3 py-2 font-medium">
                                    Q{{ item.position }}
                                </td>
                                <td class="px-3 py-2 tabular-nums">
                                    {{ item.candidates }}
                                </td>
                                <td class="px-3 py-2">
                                    <span class="tabular-nums">{{
                                        number(item.observedP)
                                    }}</span>
                                    <span
                                        v-if="item.difficultyBand"
                                        class="ml-2 rounded px-1.5 py-0.5 text-xs font-medium"
                                        :class="
                                            bandClass[item.difficultyBand.key]
                                        "
                                        data-test="difficulty-band"
                                        >{{ item.difficultyBand.label }}</span
                                    >
                                </td>
                                <td class="px-3 py-2">
                                    <span class="tabular-nums">{{
                                        number(item.discrimination)
                                    }}</span>
                                    <span
                                        v-if="item.discriminationBand"
                                        class="ml-2 rounded px-1.5 py-0.5 text-xs font-medium"
                                        :class="
                                            bandClass[
                                                item.discriminationBand.key
                                            ]
                                        "
                                        data-test="discrimination-band"
                                        >{{
                                            item.discriminationBand.label
                                        }}</span
                                    >
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <span v-if="item.options">
                                        <span
                                            v-for="option in item.options"
                                            :key="option.label"
                                            class="mr-2 whitespace-nowrap tabular-nums"
                                            :class="
                                                optionClass(
                                                    item,
                                                    option.label,
                                                    option.correct,
                                                )
                                            "
                                            >{{ option.label }}
                                            {{ percent(option.share)
                                            }}{{
                                                option.correct ? ' ✓' : ''
                                            }}</span
                                        >
                                    </span>
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    <template v-if="item.distractorAnalysis">
                                        <div
                                            v-if="
                                                item.distractorAnalysis
                                                    .efficiency !== null
                                            "
                                        >
                                            Efficiency
                                            {{
                                                item.distractorAnalysis
                                                    .efficiency
                                            }}%
                                        </div>
                                        <div
                                            v-if="hasNfd(item)"
                                            class="text-amber-700 dark:text-amber-300"
                                            data-test="nfd"
                                        >
                                            Non-functional:
                                            {{
                                                item.distractorAnalysis.nonFunctional.join(
                                                    ', ',
                                                )
                                            }}
                                        </div>
                                        <div
                                            v-if="
                                                item.distractorAnalysis
                                                    .possibleMiskey.length > 0
                                            "
                                            class="font-medium text-red-700 dark:text-red-300"
                                            data-test="miskey"
                                        >
                                            Possible miskey:
                                            {{
                                                item.distractorAnalysis.possibleMiskey.join(
                                                    ', ',
                                                )
                                            }}
                                        </div>
                                        <div
                                            v-if="
                                                item.distractorAnalysis
                                                    .defective.length > 0
                                            "
                                            class="text-red-700 dark:text-red-300"
                                            data-test="defective"
                                        >
                                            Possibly defective:
                                            {{
                                                item.distractorAnalysis.defective.join(
                                                    ', ',
                                                )
                                            }}
                                        </div>
                                    </template>
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="px-3 py-2" @click.stop>
                                    <div
                                        v-if="decisions[item.versionId]"
                                        class="mb-1 text-xs font-medium"
                                        data-test="current-decision"
                                    >
                                        {{ decisions[item.versionId].name }}
                                    </div>
                                    <template v-if="can.decide">
                                        <form
                                            v-if="deciding === item.versionId"
                                            class="grid gap-1"
                                            data-test="decide-form"
                                            @submit.prevent="submitDecide(item)"
                                        >
                                            <select
                                                v-model="decideForm.decision"
                                                data-test="decision-select"
                                                class="border-input h-8 rounded-md border bg-transparent px-2 text-xs"
                                            >
                                                <option
                                                    v-for="type in decisionTypes"
                                                    :key="type.code"
                                                    :value="type.code"
                                                >
                                                    {{ type.name }}
                                                </option>
                                            </select>
                                            <Input
                                                v-model="decideForm.reason"
                                                placeholder="Reason"
                                                maxlength="500"
                                                class="h-8"
                                                data-test="decision-reason"
                                            />
                                            <div class="flex gap-1">
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    :disabled="
                                                        decideForm.processing
                                                    "
                                                    data-test="save-decision"
                                                    >Save</Button
                                                >
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    @click="deciding = null"
                                                    >Cancel</Button
                                                >
                                            </div>
                                        </form>
                                        <Button
                                            v-else
                                            size="sm"
                                            variant="outline"
                                            class="print:hidden"
                                            data-test="start-decide"
                                            @click="startDecide(item)"
                                            >{{
                                                decisions[item.versionId]
                                                    ? 'Change'
                                                    : 'Decide'
                                            }}</Button
                                        >
                                    </template>
                                </td>
                            </tr>
                            <!-- Option by option: everyone, the upper 27% and the lower 27%. -->
                            <tr
                                v-if="open === item.paperItemId && item.options"
                                class="bg-muted/20"
                                data-test="option-detail"
                            >
                                <td colspan="7" class="px-3 py-3">
                                    <div class="grid max-w-2xl gap-1.5">
                                        <div
                                            class="text-muted-foreground grid grid-cols-[3rem_1fr_5rem_5rem] gap-2 text-xs font-medium"
                                        >
                                            <span>Option</span>
                                            <span>Chosen by everyone</span>
                                            <span>Upper 27%</span>
                                            <span>Lower 27%</span>
                                        </div>
                                        <div
                                            v-for="option in item.options"
                                            :key="option.label"
                                            class="grid grid-cols-[3rem_1fr_5rem_5rem] items-center gap-2 text-xs"
                                        >
                                            <span
                                                :class="
                                                    optionClass(
                                                        item,
                                                        option.label,
                                                        option.correct,
                                                    )
                                                "
                                                >{{ option.label
                                                }}{{
                                                    option.correct ? ' ✓' : ''
                                                }}</span
                                            >
                                            <span
                                                class="flex items-center gap-2"
                                            >
                                                <span
                                                    class="h-2 rounded"
                                                    :class="
                                                        option.correct
                                                            ? 'bg-emerald-500'
                                                            : 'bg-slate-400'
                                                    "
                                                    :style="{
                                                        width: `${Math.max(2, (option.share ?? 0) * 100)}%`,
                                                    }"
                                                ></span>
                                                <span class="tabular-nums">{{
                                                    percent(option.share)
                                                }}</span>
                                            </span>
                                            <span class="tabular-nums">{{
                                                percent(option.upper)
                                            }}</span>
                                            <span class="tabular-nums">{{
                                                percent(option.lower)
                                            }}</span>
                                        </div>
                                        <p
                                            v-if="
                                                item.options[0]?.upper === null
                                            "
                                            class="text-muted-foreground text-xs"
                                        >
                                            The upper and lower groups need at
                                            least
                                            {{ thresholds.min_candidates }}
                                            candidates.
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="shown.length === 0">
                            <td
                                colspan="7"
                                class="text-muted-foreground px-3 py-10 text-center"
                            >
                                {{
                                    items.length === 0
                                        ? 'Run analysis to see item statistics.'
                                        : 'No question matches this filter.'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="text-muted-foreground grid gap-2 text-xs">
                <p>
                    <strong>Difficulty Index</strong> = what percentage of
                    students got the question right. 0.72 means 72% got it
                    right: closer to 1.0 is easier, closer to 0 is harder.
                    Ideally most questions land in a moderate range (roughly
                    {{ thresholds.difficulty.too_hard_below }}–{{
                        thresholds.difficulty.too_easy_above
                    }}), not at the extremes.
                </p>
                <p>
                    <strong>Discrimination Index</strong> = how well the
                    question tells apart strong students from weak students. It
                    ranges from -1 to +1; a healthy question (e.g. 0.31) is well
                    above 0. A negative value is a red flag: weaker students did
                    better on that question than stronger ones, which usually
                    points to a flawed or miskeyed question.
                </p>
                <p>
                    <strong>Distractors</strong>: a distractor is simply a wrong
                    answer choice. If almost nobody picks one (under
                    {{ nfdPercent }}%), it is non-functional: it isn't doing its
                    job and could be replaced with a better wrong answer.
                    Distractor efficiency is the share of wrong options that do
                    work. A wrong option chosen more by the stronger students
                    than the weaker ones, or more than the right answer, may be
                    defective or the key may be wrong. Click a question to see
                    each option for the upper and lower 27%.
                </p>
            </div>
        </section>
    </div>
</template>
