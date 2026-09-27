<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import marking from '@/routes/marking';
import type { MarkingExaminationRow } from '@/types';

defineProps<{
    examinations: MarkingExaminationRow[];
    scopedByTeaching: boolean;
    hasTeachingAssignments: boolean;
}>();
</script>

<template>
    <Head title="Marking" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Marking"
            :description="
                scopedByTeaching
                    ? 'Examinations of the programmes you teach, and any you have been appointed to mark.'
                    : 'Examinations with something submitted to mark.'
            "
        />

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Examination</th>
                        <th class="px-3 py-2 font-medium">Programme</th>
                        <th class="px-3 py-2 font-medium">Intake</th>
                        <th class="px-3 py-2 font-medium">Submitted</th>
                        <th class="px-3 py-2 font-medium">Marking</th>
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
                            <div
                                v-if="exam.course"
                                class="text-muted-foreground text-xs"
                            >
                                {{ exam.course }}
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <div>{{ exam.programme ?? '—' }}</div>
                            <div
                                v-if="exam.year"
                                class="text-muted-foreground text-xs"
                            >
                                {{ exam.year }}
                            </div>
                        </td>
                        <td class="px-3 py-2">{{ exam.intake ?? '—' }}</td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ exam.submittedCount }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge variant="outline">{{
                                exam.requireDoubleMarking
                                    ? 'Two examiners'
                                    : 'One examiner'
                            }}</Badge>
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                :href="marking.show(exam.id)"
                                class="underline-offset-4 hover:underline"
                                data-test="open-marking"
                                >Open</Link
                            >
                        </td>
                    </tr>
                    <tr v-if="examinations.length === 0">
                        <td
                            colspan="6"
                            class="text-muted-foreground px-3 py-10 text-center"
                        >
                            <!-- An empty table means two very different things, so it says which. -->
                            <template
                                v-if="scopedByTeaching && !hasTeachingAssignments"
                            >
                                <span data-test="no-teaching-assignment">
                                    You have not been assigned to a programme
                                    yet, so there is nothing here to mark. Ask
                                    the academics office to add you under
                                    Academics → Assign Program Teacher.
                                </span>
                            </template>
                            <template v-else>
                                Nothing has been submitted yet.
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
