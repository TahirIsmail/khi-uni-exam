<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import results from '@/routes/results';
import type { PublicationStatus, ResultsExaminationRow } from '@/types';

defineProps<{
    examinations: ResultsExaminationRow[];
}>();

const statusStyle: Record<PublicationStatus, 'secondary' | 'outline' | 'default'> = {
    draft: 'secondary',
    approved: 'outline',
    published: 'default',
};
</script>

<template>
    <Head title="Results & Marks" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Results & Marks"
            description="Examinations with something submitted."
        />

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Examination</th>
                        <th class="px-3 py-2 font-medium">Submitted</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="exam in examinations"
                        :key="exam.id"
                        class="border-t"
                        :data-exam="exam.id"
                    >
                        <td class="px-3 py-2">
                            <div class="font-mono text-xs">
                                {{ exam.reference }}
                            </div>
                            <div class="font-medium">{{ exam.title }}</div>
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ exam.submittedCount }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge :variant="statusStyle[exam.status]">{{
                                exam.statusLabel
                            }}</Badge>
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                :href="results.show(exam.id)"
                                class="underline-offset-4 hover:underline"
                                data-test="open-results"
                                >Open</Link
                            >
                        </td>
                    </tr>
                    <tr v-if="examinations.length === 0">
                        <td
                            colspan="4"
                            class="text-muted-foreground px-3 py-10 text-center"
                        >
                            Nothing has been submitted yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
