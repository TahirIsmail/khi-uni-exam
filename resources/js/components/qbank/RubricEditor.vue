<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { QuestionChecks, RubricRow } from '@/types';

const props = defineProps<{
    modelValue: RubricRow[];
    marks: number;
    checks: QuestionChecks;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [RubricRow[]] }>();

const total = computed(() =>
    props.modelValue.reduce(
        (sum, row) => sum + (Number(row.max_marks) || 0),
        0,
    ),
);

function update(rows: RubricRow[]): void {
    emit(
        'update:modelValue',
        rows.map((row, index) => ({ ...row, sort_order: index + 1 })),
    );
}

function patch(index: number, changes: Partial<RubricRow>): void {
    update(
        props.modelValue.map((row, i) =>
            i === index ? { ...row, ...changes } : row,
        ),
    );
}
</script>

<template>
    <section class="grid gap-3 rounded-lg border p-4">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="font-medium">Marking rubric</h3>
                <p
                    class="text-xs"
                    :class="
                        modelValue.length > 0 && total !== marks
                            ? 'text-amber-700 dark:text-amber-500'
                            : 'text-muted-foreground'
                    "
                >
                    What the marker looks for. Rubric total {{ total }} of
                    {{ marks }} marks.
                </p>
            </div>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="disabled"
                data-test="add-criterion"
                @click="
                    update([
                        ...modelValue,
                        {
                            criterion: '',
                            max_marks: 1,
                            guidance: null,
                            sort_order: 0,
                        },
                    ])
                "
            >
                <Plus /> Add line
            </Button>
        </header>

        <ul class="grid gap-2">
            <li
                v-for="(row, index) in modelValue"
                :key="index"
                class="grid gap-2 rounded-md border p-3"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Input
                        class="h-8 flex-1"
                        :model-value="row.criterion"
                        :disabled="disabled"
                        placeholder="e.g. Mentions reperfusion within 90 minutes"
                        :aria-label="`Criterion ${index + 1}`"
                        @update:model-value="
                            patch(index, { criterion: String($event) })
                        "
                    />
                    <label class="flex items-center gap-2 text-sm">
                        <span class="text-muted-foreground">Marks</span>
                        <Input
                            class="h-8 w-20"
                            type="number"
                            step="0.5"
                            min="0"
                            :model-value="row.max_marks"
                            :disabled="disabled"
                            @update:model-value="
                                patch(index, { max_marks: Number($event) })
                            "
                        />
                    </label>
                    <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        class="text-destructive"
                        :disabled="disabled"
                        :title="`Remove line ${index + 1}`"
                        @click="
                            update(modelValue.filter((_, i) => i !== index))
                        "
                        ><Trash2
                    /></Button>
                </div>
                <textarea
                    class="border-input bg-background min-h-8 rounded-md border px-3 py-2 text-sm"
                    rows="1"
                    :value="row.guidance ?? ''"
                    :disabled="disabled"
                    placeholder="Guidance for the marker, optional"
                    @input="
                        patch(index, {
                            guidance:
                                ($event.target as HTMLTextAreaElement).value ||
                                null,
                        })
                    "
                />
                <InputError
                    v-for="(message, i) in checks.errors[`rubric.${index}`] ??
                    []"
                    :key="i"
                    :message="message"
                />
            </li>
        </ul>
    </section>
</template>
