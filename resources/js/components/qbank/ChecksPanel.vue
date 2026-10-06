<script setup lang="ts">
import { CircleAlert, CircleCheck, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import type { QuestionChecks } from '@/types';

const props = defineProps<{
    checks: QuestionChecks;
    checking?: boolean;
}>();

const errors = computed(() => Object.values(props.checks.errors).flat());
</script>

<template>
    <aside class="grid gap-3 rounded-lg border p-4" aria-live="polite">
        <h3 class="font-medium">Checks</h3>

        <p v-if="checking" class="text-muted-foreground text-sm">Checking…</p>

        <p
            v-else-if="errors.length === 0"
            class="flex items-center gap-2 text-sm text-green-700 dark:text-green-400"
            data-test="checks-ready"
        >
            <CircleCheck class="size-4 shrink-0" />
            Ready to send for review.
        </p>

        <ul v-else class="grid gap-2 text-sm" data-test="checks-errors">
            <li
                v-for="(message, index) in errors"
                :key="index"
                class="text-destructive flex items-start gap-2"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0" />
                {{ message }}
            </li>
        </ul>

        <template v-if="checks.warnings.length > 0">
            <h4 class="text-sm font-medium">Worth a second look</h4>
            <ul class="grid gap-2 text-sm" data-test="checks-warnings">
                <li
                    v-for="(message, index) in checks.warnings"
                    :key="index"
                    class="flex items-start gap-2 text-amber-700 dark:text-amber-500"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                    {{ message }}
                </li>
            </ul>
        </template>
    </aside>
</template>
