<script setup lang="ts">
import { CheckCircle2 } from '@lucide/vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    examination: { title: string };
}>();
</script>

<template>
    <Head :title="`Submitted — ${examination.title}`" />

    <div
        class="bg-background flex min-h-screen items-center justify-center p-4"
    >
        <div
            class="grid max-w-sm gap-3 text-center"
            data-test="submitted-screen"
        >
            <CheckCircle2 class="mx-auto size-10 text-green-600" />
            <h1 class="text-lg font-medium">
                {{ examination.title }} — submitted
            </h1>
            <p class="text-muted-foreground text-sm">
                Your answers have been received. You may close this window.
            </p>
        </div>
    </div>
</template>
