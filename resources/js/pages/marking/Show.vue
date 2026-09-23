<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import marking from '@/routes/marking';
import type {
    AdjudicationQueueRow,
    ExaminerRole,
    ExaminerRow,
    MarkingAbilities,
    MarkingQueueRow,
} from '@/types';

const props = defineProps<{
    examination: {
        id: number;
        reference: string;
        title: string;
        requireDoubleMarking: boolean;
    };
    examiners: ExaminerRow[];
    examinerCandidates: { id: number; name: string }[];
    myQueue: MarkingQueueRow[];
    adjudicationQueue: AdjudicationQueueRow[];
    can: MarkingAbilities;
}>();

const assignForm = useForm<{ user_id: number | null; role: ExaminerRole }>({
    user_id: null,
    role: 'first',
});
function assign(): void {
    assignForm.post(marking.examiners.assign(props.examination.id).url, {
        preserveScroll: true,
        onSuccess: () => assignForm.reset(),
    });
}

const adjudicating = ref<number | null>(null);
const adjudicateForm = useForm<{ marks_awarded: number | undefined; reason: string }>(
    { marks_awarded: undefined, reason: '' },
);
function decide(row: AdjudicationQueueRow): void {
    adjudicateForm.post(
        marking.items.adjudicate([props.examination.id, row.itemId]).url,
        {
            preserveScroll: true,
            onSuccess: () => {
                adjudicating.value = null;
                adjudicateForm.reset();
            },
        },
    );
}
</script>

<template>
    <Head :title="`Marking — ${examination.title}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Marking — ${examination.title}`"
            :description="`${examination.reference} · ${examination.requireDoubleMarking ? 'Two examiners' : 'One examiner'}`"
        />

        <section v-if="can.assign" class="grid gap-3 rounded-xl border p-4 shadow-xs">
            <h2 class="text-sm font-medium">Examiners</h2>
            <ul class="grid gap-1 text-sm">
                <li v-for="examiner in examiners" :key="examiner.id" :data-examiner="examiner.id">
                    <Badge variant="outline">{{ examiner.roleLabel }}</Badge>
                    {{ examiner.name }}
                </li>
                <li v-if="examiners.length === 0" class="text-muted-foreground">
                    Nobody assigned yet.
                </li>
            </ul>
            <form class="flex flex-wrap items-end gap-2" @submit.prevent="assign">
                <div class="grid gap-1.5">
                    <Label for="examiner-user">Examiner</Label>
                    <select
                        id="examiner-user"
                        v-model="assignForm.user_id"
                        data-test="examiner-select"
                        class="border-input h-9 min-w-48 rounded-md border bg-transparent px-3 text-sm"
                    >
                        <option :value="null" disabled>Choose…</option>
                        <option
                            v-for="candidate in examinerCandidates"
                            :key="candidate.id"
                            :value="candidate.id"
                        >
                            {{ candidate.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="examiner-role">Role</Label>
                    <select
                        id="examiner-role"
                        v-model="assignForm.role"
                        data-test="role-select"
                        class="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                    >
                        <option value="first">First examiner</option>
                        <option value="second">Second examiner</option>
                        <option value="adjudicator">Adjudicator</option>
                    </select>
                </div>
                <Button
                    type="submit"
                    :disabled="assignForm.processing"
                    data-test="assign-examiner"
                    >Assign</Button
                >
            </form>
        </section>

        <section v-if="can.mark" class="grid gap-2">
            <h2 class="text-sm font-medium">Your marking queue</h2>
            <div class="overflow-x-auto rounded-xl border shadow-xs">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Candidate</th>
                            <th class="px-3 py-2 font-medium">Item</th>
                            <th class="px-3 py-2 font-medium">Marks</th>
                            <th class="px-3 py-2 font-medium">Peer</th>
                            <th class="px-3 py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in myQueue"
                            :key="`${row.attemptId}-${row.itemId}`"
                            class="border-t"
                        >
                            <td class="px-3 py-2 font-mono text-xs">
                                {{ row.candidateNo }}
                            </td>
                            <td class="px-3 py-2">Q{{ row.position }}</td>
                            <td class="px-3 py-2 tabular-nums">
                                {{ row.marks }}
                            </td>
                            <td class="px-3 py-2">
                                <span
                                    v-if="row.peerHasMarked"
                                    class="text-muted-foreground"
                                    >Marked</span
                                >
                                <span v-else class="text-muted-foreground"
                                    >Not yet marked</span
                                >
                            </td>
                            <td class="px-3 py-2">
                                <Link
                                    :href="
                                        marking.items.show([
                                            examination.id,
                                            row.itemId,
                                        ])
                                    "
                                    class="underline-offset-4 hover:underline"
                                    data-test="mark-item"
                                    >Mark</Link
                                >
                            </td>
                        </tr>
                        <tr v-if="myQueue.length === 0">
                            <td
                                colspan="5"
                                class="text-muted-foreground px-3 py-10 text-center"
                            >
                                Nothing left for you to mark here.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="can.adjudicate" class="grid gap-2">
            <h2 class="text-sm font-medium">Pending adjudication</h2>
            <div class="overflow-x-auto rounded-xl border shadow-xs">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Candidate</th>
                            <th class="px-3 py-2 font-medium">Item</th>
                            <th class="px-3 py-2 font-medium">Examiner 1</th>
                            <th class="px-3 py-2 font-medium">Examiner 2</th>
                            <th class="px-3 py-2 font-medium">Decision</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in adjudicationQueue"
                            :key="`${row.attemptId}-${row.itemId}`"
                            class="border-t align-top"
                        >
                            <td class="px-3 py-2 font-mono text-xs">
                                {{ row.candidateNo }}
                            </td>
                            <td class="px-3 py-2">
                                Q{{ row.position }} / {{ row.marks }}
                            </td>
                            <td class="px-3 py-2 tabular-nums">
                                {{ row.examiner1 }}
                            </td>
                            <td class="px-3 py-2 tabular-nums">
                                {{ row.examiner2 }}
                            </td>
                            <td class="px-3 py-2">
                                <template v-if="adjudicating === row.itemId">
                                    <div class="grid gap-1">
                                        <Input
                                            v-model="adjudicateForm.marks_awarded"
                                            type="number"
                                            step="0.5"
                                            min="0"
                                            :max="row.marks"
                                            class="h-8 w-24"
                                            data-test="adjudicate-marks"
                                        />
                                        <Input
                                            v-model="adjudicateForm.reason"
                                            placeholder="Reason"
                                            maxlength="500"
                                            class="h-8"
                                            data-test="adjudicate-reason"
                                        />
                                        <div class="flex gap-1">
                                            <Button
                                                size="sm"
                                                :disabled="adjudicateForm.processing"
                                                data-test="save-adjudication"
                                                @click="decide(row)"
                                                >Save</Button
                                            >
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                @click="adjudicating = null"
                                                >Cancel</Button
                                            >
                                        </div>
                                    </div>
                                </template>
                                <Button
                                    v-else
                                    size="sm"
                                    variant="outline"
                                    data-test="start-adjudication"
                                    @click="adjudicating = row.itemId"
                                    >Decide</Button
                                >
                            </td>
                        </tr>
                        <tr v-if="adjudicationQueue.length === 0">
                            <td
                                colspan="5"
                                class="text-muted-foreground px-3 py-10 text-center"
                            >
                                Nothing pending adjudication.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
