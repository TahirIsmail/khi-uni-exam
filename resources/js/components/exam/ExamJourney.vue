<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import type { BlueprintStatus, PaperStatus } from '@/types';

/**
 * The way an examination is built, as the university does it: the examination, its blueprint, its
 * paper, and moderating and locking it. The step it is at is highlighted, what happens now is said
 * in one line, and a step already reached is a link, so moving between them does not mean hunting
 * for the right button.
 */
const props = defineProps<{
    /** 'new' before the examination exists, otherwise the stage of its blueprint. */
    stage: 'new' | BlueprintStatus;
    /** Left out only on the "new examination" form, where there is nothing to link to yet. */
    examId?: number;
    /** The paper's own status, once it has been started — carries the last two steps. */
    paperStatus?: PaperStatus;
}>();

const steps = ['Examination', 'Blueprint', 'Paper', 'Moderate & lock'];

const current = computed(() => {
    if (props.paperStatus !== undefined) {
        return props.paperStatus === 'draft' ? 2 : 3;
    }
    switch (props.stage) {
        case 'new':
            return 0;
        case 'draft':
        case 'submitted':
            return 1;
        default:
            return 2;
    }
});

// A step is a link once it can be reached: the blueprint once the examination exists, the paper
// once the blueprint is approved, and "Moderate & lock" once the paper has something to moderate —
// all on the one paper page, which shows the builder or the moderation view by the paper's status.
const hrefs = computed<(string | null)[]>(() => {
    if (props.examId === undefined) {
        return [null, null, null, null];
    }
    const base = `/exams/${props.examId}`;
    const paperReached = current.value >= 2;

    return [
        base,
        `${base}/blueprint`,
        paperReached ? `${base}/paper` : null,
        paperReached &&
        props.paperStatus !== undefined &&
        props.paperStatus !== 'draft'
            ? `${base}/paper`
            : null,
    ];
});

const next = computed(() => {
    if (props.paperStatus !== undefined) {
        switch (props.paperStatus) {
            case 'draft':
                return 'Fill it from the question bank, or choose questions yourself.';
            case 'submitted':
                return 'Waiting for the committee to moderate it.';
            case 'approved':
                return 'Approved — ready to be finalised and locked.';
            case 'finalised':
                return 'Finalised. Publish it when it is ready for delivery.';
            case 'published':
                return 'Published: ready for delivery.';
        }
    }
    switch (props.stage) {
        case 'new':
            return 'Say what the examination is: the course, the date and the marks.';
        case 'draft':
            return 'Plan the blueprint: which topics, and how many questions of each type.';
        case 'submitted':
            return 'Waiting for the approving committee to approve the blueprint.';
        default:
            return 'The blueprint is approved. The paper is built from the question bank next.';
    }
});
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border px-4 py-3 text-sm shadow-xs"
        data-test="exam-journey"
    >
        <ol class="flex flex-wrap items-center gap-2">
            <li
                v-for="(step, index) in steps"
                :key="step"
                class="flex items-center gap-2"
            >
                <component
                    :is="hrefs[index] ? Link : 'span'"
                    :href="hrefs[index] ?? undefined"
                    class="flex items-center gap-2"
                    :class="hrefs[index] ? 'group' : ''"
                >
                    <span
                        class="flex size-6 items-center justify-center rounded-full border text-xs font-medium"
                        :class="{
                            'bg-primary text-primary-foreground border-primary':
                                index === current,
                            'border-green-600 bg-green-600 text-white':
                                index < current,
                            'text-muted-foreground': index > current,
                            'group-hover:border-primary': hrefs[index] !== null,
                        }"
                        :data-step="index === current ? 'current' : undefined"
                    >
                        <Check v-if="index < current" class="size-3" />
                        <template v-else>{{ index + 1 }}</template>
                    </span>
                    <span
                        :class="[
                            index === current
                                ? 'font-medium'
                                : 'text-muted-foreground',
                            hrefs[index] ? 'group-hover:underline' : '',
                        ]"
                        >{{ step }}</span
                    >
                </component>
                <span
                    v-if="index < steps.length - 1"
                    class="text-muted-foreground"
                    >→</span
                >
            </li>
        </ol>
        <span class="text-muted-foreground">{{ next }}</span>
    </div>
</template>
