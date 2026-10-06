<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Eye, EyeOff, Printer } from '@lucide/vue';
import { computed, ref } from 'vue';
import CandidatePreview from '@/components/qbank/CandidatePreview.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import type {
    ExaminationDetail,
    QuestionDraft,
    QuestionTypeInfo,
    StoredVersion,
} from '@/types';

const props = defineProps<{
    examination: ExaminationDetail;
    paper: {
        versionNo: number;
        statusLabel: string;
        shuffleQuestions: boolean;
        shuffleOptions: boolean;
    };
    questions: { number: number; reference: string; version: StoredVersion }[];
    types: QuestionTypeInfo[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Examinations', href: '/exams' }] },
});

const showAnswers = ref(false);

function printPaper(): void {
    window.print();
}

const number = (value: number): string =>
    new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(
        value,
    );

const typeOf = (id: number): QuestionTypeInfo | null =>
    props.types.find((row) => row.id === id) ?? null;

const draftOf = (version: StoredVersion): QuestionDraft => ({
    question_type_id: version.questionTypeId,
    course_id: version.courseId,
    node_id: version.nodeId,
    discipline_id: version.disciplineId,
    exam_type_id: version.examTypeId,
    intake_id: version.intakeId,
    vignette: version.vignette,
    stem: version.stem,
    lead_in: version.leadIn,
    explanation: version.explanation,
    // In order as written: each candidate's own shuffle happens when they start.
    settings: { ...version.settings, shuffle_options: false },
    marks: version.marks,
    negative_marks: version.negativeMarks,
    cognitive_level_id: version.cognitiveLevelId,
    difficulty_level_id: version.difficultyLevelId,
    options: version.options,
    items: version.items,
    answers: version.answers,
    rubric: version.rubric,
    references: version.references,
    tag_ids: version.tagIds,
});

const paperMarks = computed(() =>
    props.questions.reduce((sum, row) => sum + row.version.marks, 0),
);

// How many questions of each type, as a line under the heading.
const byType = computed(() => {
    const counts = new Map<string, number>();
    for (const row of props.questions) {
        const name = typeOf(row.version.questionTypeId)?.name ?? 'Other';
        counts.set(name, (counts.get(name) ?? 0) + 1);
    }

    return [...counts.entries()].map(([name, count]) => `${count} ${name}`);
});
</script>

<template>
    <Head :title="`Paper — ${examination.title}`" />

    <div class="flex flex-col gap-6 p-4 print:p-0">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="examination.title"
                :description="`${examination.reference} · ${examination.course} · Paper version ${paper.versionNo} · ${paper.statusLabel}`"
            />
            <div class="flex flex-wrap gap-2 print:hidden">
                <Button
                    variant="outline"
                    data-test="toggle-answers"
                    @click="showAnswers = !showAnswers"
                >
                    <component :is="showAnswers ? EyeOff : Eye" />
                    {{
                        showAnswers
                            ? 'Hide the answer key'
                            : 'Show the answer key'
                    }}
                </Button>
                <Button variant="outline" @click="printPaper"
                    ><Printer /> Print</Button
                >
                <Button as-child variant="ghost">
                    <Link :href="`/exams/${examination.id}/paper`"
                        >Back to the paper</Link
                    >
                </Button>
            </div>
        </div>

        <!-- The examination, as it is set. -->
        <section
            class="grid gap-4 rounded-xl border p-4 text-sm shadow-xs sm:grid-cols-2 lg:grid-cols-4"
            data-test="paper-summary"
        >
            <div>
                <div class="text-muted-foreground text-xs">Questions</div>
                <div class="text-lg font-medium tabular-nums">
                    {{ questions.length }}
                </div>
                <div class="text-muted-foreground text-xs">
                    {{ byType.join(' · ') }}
                </div>
            </div>
            <div>
                <div class="text-muted-foreground text-xs">Marks</div>
                <div class="text-lg font-medium tabular-nums">
                    {{ number(paperMarks) }}
                    <span
                        v-if="paperMarks !== examination.totalMarks"
                        class="text-destructive text-xs font-normal"
                        data-test="marks-mismatch"
                        >of {{ number(examination.totalMarks) }} set</span
                    >
                </div>
                <div class="text-muted-foreground text-xs">
                    Pass: {{ number(examination.passMarks) }} ({{
                        examination.passPercentage
                    }}%)
                </div>
            </div>
            <div>
                <div class="text-muted-foreground text-xs">Time</div>
                <div class="text-lg font-medium tabular-nums">
                    {{ examination.durationMinutes }} minutes
                </div>
                <div class="text-muted-foreground text-xs">
                    {{ examination.startsAtLabel ?? 'Date not fixed yet' }}
                </div>
            </div>
            <div>
                <div class="text-muted-foreground text-xs">Marking</div>
                <div class="font-medium">
                    {{
                        examination.negativeMarking
                            ? `Negative marking (${examination.negativeFraction})`
                            : 'No negative marking'
                    }}
                </div>
                <div class="text-muted-foreground text-xs">
                    {{
                        paper.shuffleQuestions
                            ? 'Questions shuffled for each candidate'
                            : 'Questions in this order'
                    }}{{ paper.shuffleOptions ? ' · options shuffled' : '' }}
                </div>
            </div>
            <div
                v-if="examination.instructions"
                class="border-t pt-3 sm:col-span-2 lg:col-span-4"
            >
                <div class="text-muted-foreground mb-1 text-xs">
                    Instructions
                </div>
                <p class="whitespace-pre-line">
                    {{ examination.instructions }}
                </p>
            </div>
        </section>

        <!-- Every question, in the paper's order, with the marks this paper gives it. -->
        <div class="grid gap-4">
            <div
                v-for="row in questions"
                :key="row.number"
                class="break-inside-avoid"
                :data-question="row.number"
            >
                <CandidatePreview
                    :draft="draftOf(row.version)"
                    :type="typeOf(row.version.questionTypeId)"
                    :show-answers="showAnswers"
                    :title="`Question ${row.number}`"
                />
                <p class="text-muted-foreground mt-1 px-1 text-xs print:hidden">
                    {{ row.reference }} ·
                    {{ typeOf(row.version.questionTypeId)?.name }}
                </p>
            </div>
            <p
                v-if="questions.length === 0"
                class="text-muted-foreground rounded-xl border p-10 text-center text-sm"
            >
                This paper has no questions yet.
            </p>
        </div>
    </div>
</template>
