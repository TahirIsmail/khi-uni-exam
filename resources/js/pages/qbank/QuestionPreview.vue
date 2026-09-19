<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FilePlus2 } from '@lucide/vue';
import { computed } from 'vue';
import CandidatePreview from '@/components/qbank/CandidatePreview.vue';
import ChecksPanel from '@/components/qbank/ChecksPanel.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/questions';
import type {
    QuestionChecks,
    QuestionDraft,
    QuestionTypeInfo,
    StoredVersion,
} from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Question bank', href: index() }] },
});

const props = defineProps<{
    reference: string;
    version: StoredVersion;
    checks: QuestionChecks;
    types: QuestionTypeInfo[];
    cognitiveLevels: { id: number; name: string; description: string | null }[];
    difficultyLevels: { id: number; name: string }[];
    limits: {
        stemMin: number;
        stemMax: number;
        marksMax: number;
        referenceRequired: boolean;
    };
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

const cognitive = computed(
    () =>
        props.cognitiveLevels.find(
            (level) => level.id === props.version.cognitiveLevelId,
        )?.name ?? '—',
);
const difficulty = computed(
    () =>
        props.difficultyLevels.find(
            (level) => level.id === props.version.difficultyLevelId,
        )?.name ?? '—',
);

function startNewVersion(): void {
    router.post(`/questions/${props.version.questionId}/versions`);
}
</script>

<template>
    <Head :title="`${reference} — version ${version.versionNo}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`${reference} — version ${version.versionNo}`"
                :description="`${type?.name ?? ''} · ${version.marks} mark${version.marks === 1 ? '' : 's'}`"
            />
            <div class="flex items-center gap-2">
                <Badge variant="outline">{{ version.statusLabel }}</Badge>
                <Button v-if="version.editable" as-child variant="outline">
                    <Link
                        :href="`/questions/${version.questionId}/versions/${version.id}/edit`"
                        >Edit draft</Link
                    >
                </Button>
                <Button
                    v-else
                    type="button"
                    variant="outline"
                    data-test="new-version"
                    @click="startNewVersion"
                >
                    <FilePlus2 /> New version
                </Button>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <CandidatePreview :draft="draft" :type="type" show-answers />

            <div class="grid content-start gap-4">
                <dl class="grid gap-2 rounded-lg border p-4 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Cognitive level</dt>
                        <dd>{{ cognitive }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Difficulty level</dt>
                        <dd>{{ difficulty }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Negative marks</dt>
                        <dd>{{ version.negativeMarks }}</dd>
                    </div>
                </dl>

                <div
                    v-if="version.rubric.length > 0"
                    class="grid gap-2 rounded-lg border p-4 text-sm"
                >
                    <h3 class="font-medium">Marking rubric</h3>
                    <div
                        v-for="(row, index) in version.rubric"
                        :key="index"
                        class="flex justify-between gap-4"
                    >
                        <span>{{ row.criterion }}</span>
                        <span class="text-muted-foreground shrink-0">{{
                            row.max_marks
                        }}</span>
                    </div>
                </div>

                <ChecksPanel :checks="checks" />
            </div>
        </div>
    </div>
</template>
