<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import type { CandidateStatementData, CohortDescription } from '@/types';

const props = defineProps<{
    cohort: CohortDescription;
    statement: CandidateStatementData;
}>();

const semester = computed(() => props.statement.calendarType === 'semester');
function printSheet(): void {
    window.print();
}

const candidate = computed(() => props.statement.candidate);
</script>

<template>
    <Head title="Detailed marks certificate" />

    <div class="flex flex-col gap-6 p-4 print:p-0">
        <div class="flex items-start justify-between gap-4 print:block">
            <Heading
                title="Detailed marks certificate"
                :description="`${candidate.name} · ${candidate.candidateNo}`"
            />
            <button
                class="border-input h-9 rounded-md border px-3 py-2 text-sm print:hidden"
                @click="printSheet"
            >
                Print
            </button>
        </div>

        <dl class="grid max-w-xl grid-cols-2 gap-x-6 gap-y-1 text-sm">
            <dt class="text-muted-foreground">Programme</dt>
            <dd>{{ statement.place.programme ?? '—' }}</dd>
            <dt class="text-muted-foreground">Year / semester</dt>
            <dd>{{ statement.place.year ?? '—' }}</dd>
            <dt class="text-muted-foreground">Intake</dt>
            <dd>{{ statement.place.intake ?? '—' }}</dd>
            <dt v-if="candidate.rollNo" class="text-muted-foreground">
                Roll number
            </dt>
            <dd v-if="candidate.rollNo">{{ candidate.rollNo }}</dd>
        </dl>

        <p class="text-muted-foreground text-sm" data-test="provisional">
            <strong>Provisional.</strong> These marks are from the
            computer-based papers held in this system only. A professional
            result also counts the practical, the viva and the internal
            assessment.
        </p>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Course</th>
                        <th v-if="semester" class="px-3 py-2 font-medium">
                            Credit hours
                        </th>
                        <th class="px-3 py-2 font-medium">Marks</th>
                        <th class="px-3 py-2 font-medium">Out of</th>
                        <th class="px-3 py-2 font-medium">%</th>
                        <th class="px-3 py-2 font-medium">Grade</th>
                        <th class="px-3 py-2 font-medium">Result</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="course in statement.courses"
                        :key="course.examinationId"
                        class="border-t"
                        :data-course="course.code"
                    >
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ course.code }}</div>
                            <div class="text-muted-foreground text-xs">
                                {{ course.title }}
                            </div>
                        </td>
                        <td v-if="semester" class="px-3 py-2 tabular-nums">
                            {{ course.creditHours ?? '—' }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{
                                candidate.courses[course.examinationId]
                                    ?.totalMarks ?? '—'
                            }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ course.totalMarks }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{
                                candidate.courses[course.examinationId]
                                    ?.percentage ?? '—'
                            }}
                        </td>
                        <td class="px-3 py-2">
                            {{
                                candidate.courses[course.examinationId]
                                    ?.grade ?? '—'
                            }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                v-if="
                                    candidate.courses[course.examinationId]
                                        ?.isPass !== undefined &&
                                    candidate.courses[course.examinationId]
                                        ?.isPass !== null
                                "
                                :variant="
                                    candidate.courses[course.examinationId]
                                        ?.isPass
                                        ? 'default'
                                        : 'destructive'
                                "
                                >{{
                                    candidate.courses[course.examinationId]
                                        ?.isPass
                                        ? 'Pass'
                                        : 'Fail'
                                }}</Badge
                            >
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                </tbody>
                <tfoot class="border-t font-medium">
                    <tr>
                        <td class="px-3 py-2">Total</td>
                        <td v-if="semester" class="px-3 py-2 tabular-nums">
                            {{ candidate.creditHours ?? '—' }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ candidate.obtainedMarks }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ candidate.possibleMarks }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ candidate.percentage ?? '—' }}
                        </td>
                        <td class="px-3 py-2">
                            <span v-if="semester" data-test="gpa"
                                >GPA {{ candidate.gpa ?? '—' }}</span
                            >
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                v-if="candidate.satEverything"
                                :variant="
                                    candidate.isPass ? 'default' : 'destructive'
                                "
                                >{{ candidate.isPass ? 'Pass' : 'Fail' }}</Badge
                            >
                            <Badge v-else variant="outline">Incomplete</Badge>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div
            v-if="statement.cumulative"
            class="max-w-xl rounded-xl border p-4 text-sm shadow-xs"
            data-test="cumulative"
        >
            <div class="mb-2 font-medium">Cumulative</div>
            <table class="w-full text-left">
                <tbody>
                    <tr
                        v-for="term in statement.cumulative.terms"
                        :key="term.termId ?? 'none'"
                        class="border-t"
                    >
                        <td class="py-1">{{ term.name ?? 'This term' }}</td>
                        <td class="py-1 tabular-nums">
                            {{ term.creditHours ?? '—' }} credit hours
                        </td>
                        <td class="py-1 tabular-nums">
                            GPA {{ term.gpa ?? '—' }}
                        </td>
                    </tr>
                </tbody>
                <tfoot class="border-t font-medium">
                    <tr>
                        <td class="py-1">CGPA</td>
                        <td class="py-1 tabular-nums">
                            {{ statement.cumulative.creditHours }} credit hours
                        </td>
                        <td class="py-1 tabular-nums" data-test="cgpa">
                            {{ statement.cumulative.cgpa ?? 'Not available' }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
