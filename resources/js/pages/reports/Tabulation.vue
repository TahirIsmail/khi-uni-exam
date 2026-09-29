<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import reports from '@/routes/reports';
import type { CohortDescription, TabulationSheetData } from '@/types';

const props = defineProps<{
    cohort: CohortDescription;
    sheet: TabulationSheetData;
}>();

const semester = computed(() => props.sheet.calendarType === 'semester');

const query = computed(() => ({
    programme_id: props.cohort.programmeId,
    professional_id: props.cohort.professionalId,
    intake_id: props.cohort.intakeId,
    term_id: props.cohort.termId ?? undefined,
}));

function printSheet(): void {
    window.print();
}

function markFor(candidate: TabulationSheetData['candidates'][number], examinationId: number) {
    return candidate.courses[examinationId] ?? null;
}

/**
 * Whether every subject on this sheet counts its practical and internal assessment, and is
 * therefore the university's actual result rather than the theory paper standing in for one.
 */
const fullResult = computed(
    () =>
        props.sheet.courses.length > 0 &&
        props.sheet.courses.every((c) => c.components.length > 0),
);

/** The halves a candidate failed on their own — the rule that no total can make up for. */
function failedHalves(
    candidate: TabulationSheetData['candidates'][number],
): string[] {
    return [
        ...new Set(
            Object.values(candidate.courses).flatMap((c) => c.failedGroups),
        ),
    ];
}
</script>

<template>
    <Head title="Tabulation sheet" />

    <div class="flex flex-col gap-6 p-4 print:p-0">
        <div class="flex items-start justify-between gap-4 print:block">
            <Heading
                title="Tabulation sheet"
                :description="`${cohort.programme ?? ''} · ${cohort.year ?? ''} · ${cohort.intake ?? ''}`"
            />
            <div class="flex gap-2 print:hidden">
                <a
                    :href="reports.tabulation.csv.url({ query })"
                    class="border-input h-9 rounded-md border px-3 py-2 text-sm"
                    data-test="download-csv"
                    >Download CSV</a
                >
                <button
                    class="border-input h-9 rounded-md border px-3 py-2 text-sm"
                    @click="printSheet"
                >
                    Print
                </button>
            </div>
        </div>

        <p
            v-if="!fullResult"
            class="text-muted-foreground text-sm"
            data-test="provisional"
        >
            <strong>Provisional.</strong> These marks are from the computer-based
            papers held in this system only. A professional result also counts
            the practical, the viva and the internal assessment.
        </p>
        <p v-else class="text-muted-foreground text-sm" data-test="full-result">
            Each subject here counts its theory paper, its practical and its
            internal assessment. Theory and practical are passed separately: a
            candidate who fails one fails the subject, whatever the total says.
        </p>

        <div
            v-if="sheet.awaiting.length > 0"
            class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm"
            data-test="awaiting"
        >
            Not every examination of this group has been published yet, so this
            sheet is incomplete:
            <span v-for="exam in sheet.awaiting" :key="exam.reference">
                {{ exam.reference }} ({{ exam.title }});
            </span>
        </div>

        <div
            v-if="sheet.creditHoursMissing.length > 0"
            class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm"
            data-test="credit-hours-missing"
        >
            No GPA is worked out, because these courses have no credit hours
            recorded in the CMS:
            <strong>{{ sheet.creditHoursMissing.join(', ') }}</strong
            >.
        </div>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Candidate</th>
                        <th
                            v-for="course in sheet.courses"
                            :key="course.examinationId"
                            class="px-3 py-2 font-medium"
                        >
                            {{ course.code }}
                            <span class="block text-xs font-normal"
                                >out of {{ course.totalMarks }}</span
                            >
                        </th>
                        <th class="px-3 py-2 font-medium">Total</th>
                        <th class="px-3 py-2 font-medium">%</th>
                        <th v-if="semester" class="px-3 py-2 font-medium">
                            GPA
                        </th>
                        <th class="px-3 py-2 font-medium">Result</th>
                        <th class="px-3 py-2 font-medium">Position</th>
                        <th class="px-3 py-2 font-medium print:hidden"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="candidate in sheet.candidates"
                        :key="candidate.candidateNo"
                        class="border-t"
                        :data-candidate="candidate.candidateNo"
                    >
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ candidate.name }}</div>
                            <div class="text-muted-foreground font-mono text-xs">
                                {{ candidate.candidateNo }}
                            </div>
                            <Badge
                                v-if="candidate.identityClash"
                                variant="destructive"
                                data-test="identity-clash"
                                >Two different CNICs use this number</Badge
                            >
                        </td>
                        <td
                            v-for="course in sheet.courses"
                            :key="course.examinationId"
                            class="px-3 py-2 tabular-nums"
                        >
                            <template
                                v-if="markFor(candidate, course.examinationId)"
                            >
                                {{
                                    markFor(candidate, course.examinationId)
                                        ?.totalMarks ?? '—'
                                }}
                                <span class="text-muted-foreground text-xs">{{
                                    markFor(candidate, course.examinationId)
                                        ?.grade ?? ''
                                }}</span>
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ candidate.obtainedMarks }} /
                            {{ candidate.possibleMarks }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ candidate.percentage ?? '—' }}
                        </td>
                        <td v-if="semester" class="px-3 py-2 tabular-nums">
                            {{ candidate.gpa ?? '—' }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                v-if="!candidate.satEverything"
                                variant="outline"
                                >Incomplete</Badge
                            >
                            <Badge
                                v-else
                                :variant="
                                    candidate.isPass ? 'default' : 'destructive'
                                "
                                >{{ candidate.isPass ? 'Pass' : 'Fail' }}</Badge
                            >
                            <span
                                v-if="failedHalves(candidate).length > 0"
                                class="text-destructive block text-xs"
                                data-test="failed-halves"
                                >failed {{ failedHalves(candidate).join(' and ') }}</span
                            >
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ candidate.position ?? '—' }}
                        </td>
                        <td class="px-3 py-2 print:hidden">
                            <Link
                                :href="
                                    reports.statement.url(
                                        { candidateNo: candidate.candidateNo },
                                        { query },
                                    )
                                "
                                class="underline-offset-4 hover:underline"
                                data-test="open-statement"
                                >Certificate</Link
                            >
                        </td>
                    </tr>
                    <tr v-if="sheet.candidates.length === 0">
                        <td
                            colspan="20"
                            class="text-muted-foreground px-3 py-10 text-center"
                        >
                            No published result for this group yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
