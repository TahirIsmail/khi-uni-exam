<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';

/**
 * KMU's question journey, as KMU describes it: Create → Review → Approve → Stored in QBank.
 * The step the question is at is highlighted; what happens next is said in one line.
 */
const props = defineProps<{
    status: string;
    statusLabel: string;
    /** Reviews are all in and the approving authority is next. */
    awaitingApproval?: boolean;
}>();

const steps = ['Create', 'Review', 'Approve', 'Stored in QBank'];

const current = computed(() => {
    switch (props.status) {
        case 'draft':
        case 'changes_requested':
            return 0;
        case 'submitted':
        case 'under_review':
            return props.awaitingApproval ? 2 : 1;
        case 'approved':
        case 'active':
        case 'on_hold':
        case 'superseded':
            return 3;
        default:
            return -1;
    }
});

const removed = computed(
    () => props.status === 'archived' || props.status === 'retired',
);

const next = computed(() => {
    if (removed.value) {
        return 'Removed / discarded: this question is no longer used.';
    }
    switch (props.status) {
        case 'draft':
            return 'Write the question, then send it for review.';
        case 'changes_requested':
            return 'Sent back to revise: change it and send it again.';
        case 'submitted':
        case 'under_review':
            return props.awaitingApproval
                ? 'Reviewed — waiting for the approving authority.'
                : 'With the reviewers.';
        case 'approved':
            return 'Approved — waiting to be put into use.';
        case 'active':
            return 'In the QBank, available for examinations.';
        case 'on_hold':
            return 'In the QBank, held for review before it is used again.';
        default:
            return '';
    }
});
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border px-4 py-3 text-sm shadow-xs"
        data-test="journey"
    >
        <ol class="flex flex-wrap items-center gap-2">
            <li
                v-for="(step, index) in steps"
                :key="step"
                class="flex items-center gap-2"
            >
                <span
                    class="flex size-6 items-center justify-center rounded-full border text-xs font-medium"
                    :class="{
                        'bg-primary text-primary-foreground border-primary':
                            !removed && index === current,
                        'border-green-600 bg-green-600 text-white':
                            !removed && index < current,
                        'text-muted-foreground': removed || index > current,
                    }"
                    :data-step="index === current ? 'current' : undefined"
                >
                    <Check v-if="!removed && index < current" class="size-3" />
                    <template v-else>{{ index + 1 }}</template>
                </span>
                <span
                    :class="
                        !removed && index === current
                            ? 'font-medium'
                            : 'text-muted-foreground'
                    "
                    >{{ step }}</span
                >
                <span
                    v-if="index < steps.length - 1"
                    class="text-muted-foreground"
                    >→</span
                >
            </li>
        </ol>
        <span
            class="rounded-md px-2 py-0.5 text-xs font-medium"
            :class="
                removed
                    ? 'bg-destructive/10 text-destructive'
                    : 'bg-muted text-foreground'
            "
            data-test="kmu-status"
            >{{ statusLabel }}</span
        >
        <span class="text-muted-foreground">{{ next }}</span>
    </div>
</template>
