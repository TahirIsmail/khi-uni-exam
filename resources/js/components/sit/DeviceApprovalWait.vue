<script setup lang="ts">
defineProps<{ examinationTitle: string }>();
</script>

<template>
    <div
        class="bg-background/95 fixed inset-0 z-50 flex flex-col items-center justify-center gap-3 p-6 text-center"
        data-test="device-waiting"
    >
        <h1 class="text-lg font-medium">Waiting for your invigilator</h1>
        <p class="text-muted-foreground max-w-sm text-sm">
            This computer has not been approved for
            {{ examinationTitle }} yet. Please tell your invigilator — the
            exam will start as soon as they approve this device.
        </p>
    </div>
</template>
