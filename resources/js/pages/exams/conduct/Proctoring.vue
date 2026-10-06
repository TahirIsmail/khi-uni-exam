<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { AlertTriangle } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import conduct from '@/routes/conduct';
import type {
    ExaminationDetail,
    MonitorRow,
    ProctorSeverity,
} from '@/types';

const props = defineProps<{
    examination: ExaminationDetail;
    attempts: MonitorRow[];
}>();

const severityStyle: Record<ProctorSeverity, 'outline' | 'secondary' | 'destructive'> = {
    low: 'outline',
    medium: 'secondary',
    high: 'destructive',
};

const flagged = props.attempts.filter((attempt) => attempt.proctorEventCount > 0);
</script>

<template>
    <Head :title="`Proctoring — ${examination.title}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Proctoring — ${examination.title}`"
            :description="`${examination.reference} · ${examination.course}`"
        />

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Candidate</th>
                        <th class="px-3 py-2 font-medium">Events</th>
                        <th class="px-3 py-2 font-medium">Highest severity</th>
                        <th class="px-3 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="attempt in flagged"
                        :key="attempt.id"
                        class="border-t"
                        :data-attempt="attempt.id"
                    >
                        <td class="px-3 py-2">
                            <div class="font-mono text-xs">
                                {{ attempt.candidateNo }}
                            </div>
                            <div class="font-medium">{{ attempt.name }}</div>
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ attempt.proctorEventCount }}
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="
                                    severityStyle[
                                        attempt.proctorHighestSeverity ??
                                            'low'
                                    ]
                                "
                            >
                                <AlertTriangle class="size-3" />
                                {{ attempt.proctorHighestSeverity }}
                            </Badge>
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                :href="
                                    conduct.proctoring.case([
                                        examination.id,
                                        attempt.id,
                                    ])
                                "
                                class="underline-offset-4 hover:underline"
                                data-test="open-case"
                                >Review</Link
                            >
                        </td>
                    </tr>
                    <tr v-if="flagged.length === 0">
                        <td
                            colspan="4"
                            class="text-muted-foreground px-3 py-10 text-center"
                        >
                            No proctoring events reported for this
                            examination.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
