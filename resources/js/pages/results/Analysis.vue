<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import results from '@/routes/results';
import type {
    AnalyticsAbilities,
    ItemAnalysisRow,
    PosthocDecisionOption,
    Reliability,
    TosMix,
    TosRow,
} from '@/types';

const props = defineProps<{
    examination: { id: number; reference: string; title: string };
    items: ItemAnalysisRow[];
    reliability: Reliability;
    tos: { rows: TosRow[]; mixes: TosMix[] };
    decisionTypes: PosthocDecisionOption[];
    can: AnalyticsAbilities;
}>();

function runAnalysis(): void {
    router.post(results.analysis.run(props.examination.id).url, {}, { preserveScroll: true });
}

const deciding = ref<number | null>(null);
const decideForm = useForm<{ decision: string; reason: string }>({
    decision: props.decisionTypes[0]?.code ?? '',
    reason: '',
});

function startDecide(item: ItemAnalysisRow): void {
    deciding.value = item.versionId;
    decideForm.reset();
}
function submitDecide(item: ItemAnalysisRow): void {
    decideForm.post(results.questions.decide([props.examination.id, item.versionId]).url, {
        preserveScroll: true,
        onSuccess: () => {
            deciding.value = null;
            decideForm.reset();
        },
    });
}

function percent(value: number | null): string {
    return value === null ? '—' : `${Math.round(value * 100)}%`;
}
</script>

<template>
    <Head :title="`Analysis — ${examination.title}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`Analysis — ${examination.title}`"
                :description="examination.reference"
            />
            <Button
                v-if="can.run"
                size="sm"
                data-test="run-analysis"
                @click="runAnalysis"
                >Run analysis</Button
            >
        </div>

        <section class="grid gap-2 rounded-xl border p-4 shadow-xs">
            <h2 class="text-sm font-medium">Reliability</h2>
            <p class="text-sm" data-test="reliability">
                <template v-if="reliability.coefficient !== null">
                    {{ reliability.label }}: {{ reliability.coefficient }}
                    ({{ reliability.candidates }} candidates)
                </template>
                <template v-else>
                    Not enough candidates yet ({{ reliability.candidates }}) for a
                    meaningful {{ reliability.label }}.
                </template>
            </p>
        </section>

        <section v-if="tos.rows.length > 0" class="grid gap-2">
            <h2 class="text-sm font-medium">Compliance with the table of specification</h2>
            <div class="overflow-x-auto rounded-xl border shadow-xs">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Row</th>
                            <th class="px-3 py-2 font-medium">Planned</th>
                            <th class="px-3 py-2 font-medium">Delivered</th>
                            <th class="px-3 py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, index) in tos.rows"
                            :key="index"
                            class="border-t"
                        >
                            <td class="px-3 py-2">Row {{ index + 1 }}</td>
                            <td class="px-3 py-2 tabular-nums">
                                {{ row.plannedCount }} questions /
                                {{ row.plannedMarks }} marks
                            </td>
                            <td class="px-3 py-2 tabular-nums">
                                {{ row.deliveredCount }} questions /
                                {{ row.deliveredMarks }} marks
                            </td>
                            <td class="px-3 py-2">
                                <Badge :variant="row.compliant ? 'default' : 'destructive'">{{
                                    row.compliant ? 'Compliant' : 'Mismatch'
                                }}</Badge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="tos.mixes.length > 0" class="flex flex-wrap gap-3 text-sm">
                <span
                    v-for="(mix, index) in tos.mixes"
                    :key="index"
                    class="text-muted-foreground"
                >
                    {{ mix.dimension }} level {{ mix.levelId }}: planned
                    {{ mix.plannedPercent }}%, delivered {{ mix.deliveredPercent }}%
                </span>
            </div>
        </section>

        <section class="grid gap-2">
            <h2 class="text-sm font-medium">Item analysis</h2>
            <div class="overflow-x-auto rounded-xl border shadow-xs">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">Item</th>
                            <th class="px-3 py-2 font-medium">Candidates</th>
                            <th class="px-3 py-2 font-medium">Difficulty (p)</th>
                            <th class="px-3 py-2 font-medium">Discrimination</th>
                            <th class="px-3 py-2 font-medium">Distractors</th>
                            <th class="px-3 py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in items"
                            :key="item.paperItemId"
                            class="border-t align-top"
                            :data-item="item.paperItemId"
                        >
                            <td class="px-3 py-2">Q{{ item.position }}</td>
                            <td class="px-3 py-2 tabular-nums">
                                {{ item.candidates }}
                            </td>
                            <td class="px-3 py-2 tabular-nums">
                                {{ percent(item.observedP) }}
                            </td>
                            <td class="px-3 py-2 tabular-nums">
                                {{
                                    item.discrimination === null
                                        ? '—'
                                        : item.discrimination
                                }}
                            </td>
                            <td class="px-3 py-2 text-xs">
                                <span v-if="item.distractors">
                                    <span
                                        v-for="(share, label) in item.distractors"
                                        :key="label"
                                        class="mr-2"
                                        >{{ label }}: {{ percent(share) }}</span
                                    >
                                </span>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="px-3 py-2">
                                <template v-if="can.decide">
                                    <template v-if="deciding === item.versionId">
                                        <form
                                            class="grid gap-1"
                                            data-test="decide-form"
                                            @submit.prevent="submitDecide(item)"
                                        >
                                            <select
                                                v-model="decideForm.decision"
                                                data-test="decision-select"
                                                class="border-input h-8 rounded-md border bg-transparent px-2 text-xs"
                                            >
                                                <option
                                                    v-for="type in decisionTypes"
                                                    :key="type.code"
                                                    :value="type.code"
                                                >
                                                    {{ type.name }}
                                                </option>
                                            </select>
                                            <Input
                                                v-model="decideForm.reason"
                                                placeholder="Reason"
                                                maxlength="500"
                                                class="h-8"
                                                data-test="decision-reason"
                                            />
                                            <div class="flex gap-1">
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    :disabled="decideForm.processing"
                                                    data-test="save-decision"
                                                    >Save</Button
                                                >
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    @click="deciding = null"
                                                    >Cancel</Button
                                                >
                                            </div>
                                        </form>
                                    </template>
                                    <Button
                                        v-else
                                        size="sm"
                                        variant="outline"
                                        data-test="start-decide"
                                        @click="startDecide(item)"
                                        >Decide</Button
                                    >
                                </template>
                            </td>
                        </tr>
                        <tr v-if="items.length === 0">
                            <td
                                colspan="6"
                                class="text-muted-foreground px-3 py-10 text-center"
                            >
                                Run analysis to see item statistics.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
