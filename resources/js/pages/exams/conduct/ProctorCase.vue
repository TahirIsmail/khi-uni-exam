<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import conduct from '@/routes/conduct';
import type {
    ProctorCase,
    ProctorDecisionType,
    ProctorSeverity,
} from '@/types';

const props = defineProps<{
    examination: { id: number; title: string };
    proctorCase: ProctorCase;
    can: { decide: boolean };
}>();

const severityStyle: Record<ProctorSeverity, 'outline' | 'secondary' | 'destructive'> = {
    low: 'outline',
    medium: 'secondary',
    high: 'destructive',
};

const decisionOptions: { value: ProctorDecisionType; label: string }[] = [
    { value: 'no_action', label: 'No action' },
    { value: 'warning', label: 'Warning' },
    { value: 'flagged_for_review', label: 'Flagged for review' },
    { value: 'void_attempt', label: 'Void this attempt' },
];

const form = useForm<{ decision: ProctorDecisionType; reason: string }>({
    decision: 'no_action',
    reason: '',
});

function decide(): void {
    if (
        form.decision === 'void_attempt' &&
        !window.confirm(
            `Void ${props.proctorCase.attempt.name}'s attempt? This cannot be undone.`,
        )
    ) {
        return;
    }
    form.post(
        conduct.proctoring.decide([
            props.examination.id,
            props.proctorCase.attempt.id,
        ]).url,
        { onSuccess: () => form.reset('reason') },
    );
}
</script>

<template>
    <Head :title="`Proctoring case — ${proctorCase.attempt.name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Proctoring case — ${proctorCase.attempt.name}`"
            :description="`${proctorCase.attempt.candidateNo} · ${examination.title} · ${proctorCase.attempt.statusLabel}`"
        />

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">When</th>
                        <th class="px-3 py-2 font-medium">Event</th>
                        <th class="px-3 py-2 font-medium">Severity</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="event in proctorCase.events"
                        :key="event.id"
                        class="border-t"
                        :data-event="event.id"
                    >
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ new Date(event.occurredAt).toLocaleString() }}
                        </td>
                        <td class="px-3 py-2">{{ event.typeLabel }}</td>
                        <td class="px-3 py-2">
                            <Badge :variant="severityStyle[event.severity]">{{
                                event.severity
                            }}</Badge>
                        </td>
                    </tr>
                    <tr v-if="proctorCase.events.length === 0">
                        <td
                            colspan="3"
                            class="text-muted-foreground px-3 py-10 text-center"
                        >
                            No events reported.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="proctorCase.decisions.length > 0" class="grid gap-2">
            <h2 class="text-sm font-medium">Decisions so far</h2>
            <div
                v-for="decision in proctorCase.decisions"
                :key="decision.id"
                class="rounded-lg border p-3 text-sm"
                :data-decision="decision.id"
            >
                <div class="flex items-center justify-between">
                    <span class="font-medium">{{
                        decision.decisionLabel
                    }}</span>
                    <span class="text-muted-foreground text-xs">{{
                        new Date(decision.decidedAt).toLocaleString()
                    }}</span>
                </div>
                <p class="text-muted-foreground mt-1">{{ decision.reason }}</p>
                <p class="text-muted-foreground mt-1 text-xs">
                    by {{ decision.decidedBy }}
                </p>
            </div>
        </div>

        <form
            v-if="can.decide"
            class="grid max-w-md gap-3 rounded-xl border p-4 shadow-xs"
            data-test="decision-form"
            @submit.prevent="decide"
        >
            <h2 class="text-sm font-medium">Record a decision</h2>
            <div class="grid gap-1.5">
                <Label for="decision">Decision</Label>
                <select
                    id="decision"
                    v-model="form.decision"
                    data-test="decision-select"
                    class="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                >
                    <option
                        v-for="option in decisionOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="reason">Reason</Label>
                <Input
                    id="reason"
                    v-model="form.reason"
                    maxlength="500"
                    data-test="decision-reason"
                />
                <p v-if="form.errors.reason" class="text-destructive text-sm">
                    {{ form.errors.reason }}
                </p>
            </div>
            <Button
                type="submit"
                class="justify-self-start"
                :disabled="form.processing"
                data-test="save-decision"
                >Record decision</Button
            >
        </form>
    </div>
</template>
