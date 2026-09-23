<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import results from '@/routes/results';
import type {
    PublicationStatus,
    RekeyDecision,
    ResultItemRow,
    ResultRow,
    ResultsAbilities,
    ResultsPublication,
} from '@/types';

const props = defineProps<{
    examination: { id: number; reference: string; title: string };
    attempts: ResultRow[];
    items: ResultItemRow[];
    publication: ResultsPublication;
    can: ResultsAbilities;
}>();

const statusStyle: Record<PublicationStatus, 'secondary' | 'outline' | 'default'> = {
    draft: 'secondary',
    approved: 'outline',
    published: 'default',
};

function approve(): void {
    router.post(results.approve(props.examination.id).url, {}, { preserveScroll: true });
}
function publish(): void {
    router.post(results.publish(props.examination.id).url, {}, { preserveScroll: true });
}

const rekeying = ref<number | null>(null);
const rekeyForm = useForm<{
    decision: RekeyDecision;
    corrected_option_id: number | undefined;
    reason: string;
}>({ decision: 'discard', corrected_option_id: undefined, reason: '' });

function startRekey(item: ResultItemRow): void {
    rekeying.value = item.id;
    rekeyForm.reset();
    rekeyForm.decision = item.hasItems ? 'discard' : 'discard';
}
function submitRekey(item: ResultItemRow): void {
    rekeyForm.post(results.items.rekey([props.examination.id, item.id]).url, {
        preserveScroll: true,
        onSuccess: () => {
            rekeying.value = null;
            rekeyForm.reset();
        },
    });
}
</script>

<template>
    <Head :title="`Results — ${examination.title}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`Results — ${examination.title}`"
                :description="examination.reference"
            />
            <div class="flex items-center gap-2">
                <Badge :variant="statusStyle[publication.status]">{{
                    publication.statusLabel
                }}</Badge>
                <Button
                    v-if="can.approve && publication.status === 'draft'"
                    size="sm"
                    data-test="approve-results"
                    @click="approve"
                    >Approve</Button
                >
                <Button
                    v-if="can.publish && publication.status === 'approved'"
                    size="sm"
                    data-test="publish-results"
                    @click="publish"
                    >Publish</Button
                >
                <Link
                    v-if="can.analytics"
                    :href="results.analysis(examination.id)"
                    class="text-sm underline-offset-4 hover:underline"
                    data-test="analysis-link"
                    >Item analysis</Link
                >
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Candidate</th>
                        <th class="px-3 py-2 font-medium">Raw</th>
                        <th class="px-3 py-2 font-medium">Deduction</th>
                        <th class="px-3 py-2 font-medium">Total</th>
                        <th class="px-3 py-2 font-medium">%</th>
                        <th class="px-3 py-2 font-medium">Result</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in attempts"
                        :key="row.attemptId"
                        class="border-t"
                        :data-attempt="row.attemptId"
                    >
                        <td class="px-3 py-2">
                            <div class="font-mono text-xs">
                                {{ row.candidateNo }}
                            </div>
                            <div class="font-medium">{{ row.name }}</div>
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ row.rawMarks }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ row.negativeDeduction }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ row.totalMarks }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ row.percentage }}%
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                v-if="row.pendingItems"
                                variant="secondary"
                                data-test="pending-items"
                                >Pending marking</Badge
                            >
                            <Badge
                                v-else
                                :variant="row.isPass ? 'default' : 'destructive'"
                                >{{ row.isPass ? 'Pass' : 'Fail' }}</Badge
                            >
                        </td>
                    </tr>
                    <tr v-if="attempts.length === 0">
                        <td
                            colspan="6"
                            class="text-muted-foreground px-3 py-10 text-center"
                        >
                            Nobody has submitted this examination yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <section v-if="can.rescore" class="grid gap-2">
            <h2 class="text-sm font-medium">Re-key an item</h2>
            <p class="text-muted-foreground text-xs">
                Corrects this paper's own item, not the reusable question — every candidate who
                sat it is rescored.
            </p>
            <div
                v-for="item in items"
                :key="item.id"
                class="rounded-lg border p-3 text-sm"
                :data-item="item.id"
            >
                <div class="flex items-center justify-between gap-2">
                    <span>Q{{ item.position }} ({{ item.marks }} marks)</span>
                    <Badge v-if="item.alreadyRekeyed" variant="secondary"
                        >Already re-keyed</Badge
                    >
                    <Badge v-else-if="item.isManuallyMarked" variant="outline"
                        >Essay — mark again instead</Badge
                    >
                    <Button
                        v-else
                        size="sm"
                        variant="outline"
                        data-test="start-rekey"
                        @click="startRekey(item)"
                        >Re-key</Button
                    >
                </div>
                <form
                    v-if="rekeying === item.id"
                    class="mt-2 grid gap-2"
                    data-test="rekey-form"
                    @submit.prevent="submitRekey(item)"
                >
                    <div class="grid gap-1.5">
                        <Label :for="`decision-${item.id}`">Decision</Label>
                        <select
                            :id="`decision-${item.id}`"
                            v-model="rekeyForm.decision"
                            data-test="rekey-decision"
                            class="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                        >
                            <option value="discard">
                                Discard (full marks to everyone)
                            </option>
                            <option v-if="!item.hasItems" value="correct_option">
                                Change the correct option
                            </option>
                        </select>
                    </div>
                    <div
                        v-if="rekeyForm.decision === 'correct_option'"
                        class="grid gap-1.5"
                    >
                        <Label :for="`option-${item.id}`"
                            >Correct option</Label
                        >
                        <select
                            :id="`option-${item.id}`"
                            v-model="rekeyForm.corrected_option_id"
                            data-test="rekey-option"
                            class="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                        >
                            <option :value="undefined" disabled>
                                Choose…
                            </option>
                            <option
                                v-for="option in item.options"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.label }} — {{ option.body }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`reason-${item.id}`">Reason</Label>
                        <Input
                            :id="`reason-${item.id}`"
                            v-model="rekeyForm.reason"
                            maxlength="500"
                            data-test="rekey-reason"
                        />
                    </div>
                    <div class="flex gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="rekeyForm.processing"
                            data-test="save-rekey"
                            >Save</Button
                        >
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="rekeying = null"
                            >Cancel</Button
                        >
                    </div>
                </form>
            </div>
        </section>
    </div>
</template>
