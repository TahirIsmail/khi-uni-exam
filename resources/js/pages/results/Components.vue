<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import results from '@/routes/results';
import type { ResultComponentRow, ComponentCandidate } from '@/types';

const props = defineProps<{
    examination: {
        id: number;
        reference: string;
        title: string;
        totalMarks: number;
        passPercentage: number;
    };
    components: ResultComponentRow[];
    candidates: ComponentCandidate[];
    marks: Record<number, Record<number, number>>;
}>();

/** The ones a person types in; the theory paper is this system's own and is never entered. */
const entered = computed(() => props.components.filter((c) => !c.isPaper));

const chosen = ref<number | null>(entered.value[0]?.id ?? null);
const chosenComponent = computed(
    () => entered.value.find((c) => c.id === chosen.value) ?? null,
);

const sheet = useForm<{ component_id: number | null; marks: Record<number, string> }>({
    component_id: null,
    marks: {},
});

function pick(componentId: number): void {
    chosen.value = componentId;
    sheet.marks = Object.fromEntries(
        props.candidates.map((c) => [
            c.id,
            props.marks[componentId]?.[c.id]?.toString() ?? '',
        ]),
    );
}

if (chosen.value !== null) {
    pick(chosen.value);
}

function save(): void {
    sheet
        .transform((data) => ({ ...data, component_id: chosen.value }))
        .post(results.components.store(props.examination.id).url, {
            preserveScroll: true,
        });
}

/** Setting up what the result is made of, for an examination that has no components yet. */
const blueprint = useForm<{
    components: {
        code: string;
        name: string;
        max_marks: string;
        group: string;
        min_pass_percentage: string;
    }[];
}>({
    components: [
        { code: 'ospe', name: 'Practical / OSPE', max_marks: '', group: 'practical', min_pass_percentage: '50' },
        { code: 'viva', name: 'Structured viva', max_marks: '', group: 'practical', min_pass_percentage: '' },
        { code: 'internal', name: 'Internal assessment', max_marks: '', group: 'theory', min_pass_percentage: '' },
    ],
});

function saveBlueprint(): void {
    blueprint
        .transform((data) => ({
            components: data.components
                .filter((c) => c.max_marks !== '')
                .map((c) => ({
                    ...c,
                    max_marks: Number(c.max_marks),
                    min_pass_percentage:
                        c.min_pass_percentage === ''
                            ? null
                            : Number(c.min_pass_percentage),
                })),
        }))
        .post(results.components.define(props.examination.id).url);
}

const subjectTotal = computed(() =>
    props.components.reduce((sum, c) => sum + c.maxMarks, 0),
);
</script>

<template>
    <Head :title="`What the result is made of — ${examination.title}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Practical, viva and internal assessment"
            :description="`${examination.reference} · ${examination.title}`"
        />

        <p class="text-muted-foreground max-w-prose text-sm">
            This system runs the theory paper and marks it. The rest of a
            professional result — the practical or OSPE, the structured viva,
            and the internal assessment carried from the year's class work — is
            decided elsewhere and recorded here. Every entry is kept with your
            name and the time against it.
        </p>

        <section v-if="components.length === 0" class="grid gap-3">
            <h2 class="text-sm font-medium">
                Say what this subject's result is made of
            </h2>
            <p class="text-muted-foreground max-w-prose text-sm">
                The theory paper is added for you, worth its
                {{ examination.totalMarks }} marks. Fill in what each other part
                is worth and leave the rest blank. A part marked as needing a
                minimum must be passed on its own — a candidate who fails the
                practical fails the subject, however well they did on paper.
            </p>

            <form class="grid gap-4" @submit.prevent="saveBlueprint">
                <div
                    v-for="(row, index) in blueprint.components"
                    :key="index"
                    class="grid gap-3 rounded-xl border p-4 shadow-xs sm:grid-cols-4"
                >
                    <div class="grid gap-1.5">
                        <Label :for="`name-${index}`">Part</Label>
                        <Input :id="`name-${index}`" v-model="row.name" maxlength="80" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`max-${index}`">Out of</Label>
                        <Input
                            :id="`max-${index}`"
                            v-model="row.max_marks"
                            type="number"
                            step="0.5"
                            min="0"
                            :data-test="`max-${row.code}`"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`group-${index}`">Counts as</Label>
                        <select
                            :id="`group-${index}`"
                            v-model="row.group"
                            class="border-input h-9 rounded-md border px-3 text-sm"
                        >
                            <option value="theory">Theory</option>
                            <option value="practical">Practical</option>
                        </select>
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`bar-${index}`">Must reach (%)</Label>
                        <Input
                            :id="`bar-${index}`"
                            v-model="row.min_pass_percentage"
                            type="number"
                            step="1"
                            min="0"
                            max="100"
                            placeholder="none"
                        />
                    </div>
                </div>

                <p v-if="blueprint.errors.components" class="text-destructive text-sm">
                    {{ blueprint.errors.components }}
                </p>

                <Button
                    type="submit"
                    class="justify-self-start"
                    :disabled="blueprint.processing"
                    data-test="save-blueprint"
                    >Save</Button
                >
            </form>
        </section>

        <template v-else>
            <section class="grid gap-2">
                <h2 class="text-sm font-medium">
                    This subject is out of {{ subjectTotal }} marks
                </h2>
                <div class="flex flex-wrap gap-2">
                    <Badge
                        v-for="component in components"
                        :key="component.id"
                        :variant="component.isPaper ? 'secondary' : 'outline'"
                    >
                        {{ component.name }} — {{ component.maxMarks }}
                        <span v-if="component.minPassPercentage !== null">
                            · {{ component.minPassPercentage }}% to pass
                            {{ component.group }}</span
                        >
                    </Badge>
                </div>
            </section>

            <section v-if="entered.length > 0" class="grid gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-for="component in entered"
                        :key="component.id"
                        type="button"
                        size="sm"
                        :variant="chosen === component.id ? 'default' : 'outline'"
                        :data-test="`pick-${component.code}`"
                        @click="pick(component.id)"
                        >{{ component.name }}</Button
                    >
                </div>

                <form
                    v-if="chosenComponent"
                    class="grid gap-3"
                    @submit.prevent="save"
                >
                    <div class="overflow-x-auto rounded-xl border shadow-xs">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-muted/50 text-muted-foreground">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Candidate</th>
                                    <th class="px-3 py-2 font-medium">Name</th>
                                    <th class="px-3 py-2 font-medium">
                                        {{ chosenComponent.name }} (out of
                                        {{ chosenComponent.maxMarks }})
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="candidate in candidates"
                                    :key="candidate.id"
                                    class="border-t"
                                >
                                    <td class="px-3 py-2 font-mono text-xs">
                                        {{ candidate.candidateNo }}
                                    </td>
                                    <td class="px-3 py-2">{{ candidate.name }}</td>
                                    <td class="px-3 py-2">
                                        <Input
                                            v-model="sheet.marks[candidate.id]"
                                            type="number"
                                            step="0.5"
                                            min="0"
                                            :max="chosenComponent.maxMarks"
                                            class="max-w-28"
                                            :data-test="`mark-${candidate.candidateNo}`"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="text-muted-foreground text-xs">
                        A box left blank is not a nil — it means nobody has
                        marked that candidate yet, and their result stays
                        incomplete until somebody does.
                    </p>

                    <Button
                        type="submit"
                        class="justify-self-start"
                        :disabled="sheet.processing"
                        data-test="save-marks"
                        >Save {{ chosenComponent.name }}</Button
                    >
                </form>
            </section>
        </template>
    </div>
</template>
