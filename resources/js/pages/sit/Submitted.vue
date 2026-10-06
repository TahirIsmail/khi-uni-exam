<script setup lang="ts">
import { CheckCircle2, PartyPopper, RotateCcw } from '@lucide/vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    examination: { title: string };
    /** Shown only when the examination gives candidates their result on submitting. */
    result: {
        awarded: number;
        total: number;
        percent: number;
        passMarks: number;
        passed: boolean;
        pending: number;
    } | null;
}>();
</script>

<template>
    <Head :title="`Submitted — ${examination.title}`" />

    <div
        class="bg-background flex min-h-screen items-center justify-center p-4"
    >
        <div
            class="grid w-full max-w-md gap-4 text-center"
            data-test="submitted-screen"
        >
            <template v-if="result && result.pending === 0">
                <div
                    v-if="result.passed"
                    class="grid gap-3 rounded-2xl border border-emerald-300 bg-emerald-50 p-6 text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-50"
                    data-test="result-passed"
                >
                    <PartyPopper class="mx-auto size-12" />
                    <h1 class="text-2xl font-semibold">Congratulations!</h1>
                    <p>You have passed {{ examination.title }}.</p>
                </div>
                <div
                    v-else
                    class="grid gap-3 rounded-2xl border border-amber-300 bg-amber-50 p-6 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-50"
                    data-test="result-failed"
                >
                    <RotateCcw class="mx-auto size-12" />
                    <h1 class="text-2xl font-semibold">Not this time</h1>
                    <p>
                        You did not reach the pass mark in
                        {{ examination.title }}. Keep going — prepare well and
                        try again next time.
                    </p>
                </div>

                <div
                    class="grid grid-cols-3 gap-2 rounded-xl border p-4"
                    data-test="result-score"
                >
                    <div>
                        <div class="text-2xl font-semibold tabular-nums">
                            {{ result.awarded }}
                        </div>
                        <div class="text-muted-foreground text-xs">
                            of {{ result.total }} marks
                        </div>
                    </div>
                    <div>
                        <div class="text-2xl font-semibold tabular-nums">
                            {{ result.percent }}%
                        </div>
                        <div class="text-muted-foreground text-xs">score</div>
                    </div>
                    <div>
                        <div class="text-2xl font-semibold tabular-nums">
                            {{ result.passMarks }}
                        </div>
                        <div class="text-muted-foreground text-xs">
                            marks to pass
                        </div>
                    </div>
                </div>
            </template>

            <template v-else>
                <CheckCircle2 class="mx-auto size-10 text-green-600" />
                <h1 class="text-lg font-medium">
                    {{ examination.title }} — submitted
                </h1>
                <p class="text-muted-foreground text-sm">
                    <template v-if="result">
                        Your answers have been received. Some still need an
                        examiner, so your result will be announced later.
                    </template>
                    <template v-else>
                        Your answers have been received. You may close this
                        window.
                    </template>
                </p>
            </template>
        </div>
    </div>
</template>
