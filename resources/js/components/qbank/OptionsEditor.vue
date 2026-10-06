<script setup lang="ts">
import { GripVertical, Lock, Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { OptionRow, QuestionChecks, QuestionTypeInfo } from '@/types';

const props = defineProps<{
    modelValue: OptionRow[];
    type: QuestionTypeInfo;
    checks: QuestionChecks;
    disabled?: boolean;
    partialCredit: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [OptionRow[]] }>();

const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
// For matching and EMQ the answer belongs to each sub-part, not to the option.
const marksCorrectHere = computed(
    () => props.type.correctMin > 0 || props.type.itemAnswer !== 'option',
);
const singleCorrect = computed(() => props.type.correctMax === 1);
const correctCount = computed(
    () => props.modelValue.filter((option) => option.is_correct).length,
);

function update(rows: OptionRow[]): void {
    emit(
        'update:modelValue',
        rows.map((row, index) => ({
            ...row,
            label: letters[index] ?? String(index + 1),
            sort_order: index + 1,
        })),
    );
}

function add(): void {
    if (props.modelValue.length >= props.type.optionsMax) {
        return;
    }
    update([
        ...props.modelValue,
        {
            label: '',
            body: '',
            is_correct: false,
            weight: null,
            feedback: null,
            sort_order: 0,
            is_position_locked: false,
            item_index: null,
        },
    ]);
}

function remove(index: number): void {
    update(props.modelValue.filter((_, i) => i !== index));
}

function move(index: number, by: number): void {
    const rows = [...props.modelValue];
    const target = index + by;
    if (target < 0 || target >= rows.length) {
        return;
    }
    [rows[index], rows[target]] = [rows[target], rows[index]];
    update(rows);
}

function setCorrect(index: number, on: boolean | 'indeterminate'): void {
    update(
        props.modelValue.map((row, i) => ({
            ...row,
            is_correct: singleCorrect.value
                ? i === index && on === true
                : i === index
                  ? on === true
                  : row.is_correct,
        })),
    );
}

function patch(index: number, changes: Partial<OptionRow>): void {
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
                <h3 class="font-medium">Options</h3>
                <p class="text-muted-foreground text-xs">
                    {{ type.optionsMin }}–{{ type.optionsMax }} options.
                    <template v-if="marksCorrectHere">
                        {{
                            singleCorrect
                                ? 'Mark the one correct option.'
                                : `Mark the correct options (${correctCount} marked).`
                        }}
                    </template>
                    <template v-else>
                        The answers are chosen for each part below.
                    </template>
                </p>
            </div>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="disabled || modelValue.length >= type.optionsMax"
                data-test="add-option"
                @click="add"
            >
                <Plus /> Add option
            </Button>
        </header>

        <InputError
            v-for="(message, i) in checks.errors['options'] ?? []"
            :key="i"
            :message="message"
        />

        <ul class="grid gap-2">
            <li
                v-for="(option, index) in modelValue"
                :key="index"
                class="grid gap-2 rounded-md border p-3"
                :data-option="option.label"
            >
                <div class="flex items-start gap-2">
                    <Badge
                        variant="secondary"
                        class="mt-1.5 w-7 justify-center"
                        >{{ option.label }}</Badge
                    >
                    <div class="grid flex-1 gap-1.5">
                        <textarea
                            class="border-input bg-background min-h-9 rounded-md border px-3 py-2 text-sm"
                            rows="1"
                            :value="option.body"
                            :disabled="disabled"
                            :aria-label="`Option ${option.label}`"
                            @input="
                                patch(index, {
                                    body: ($event.target as HTMLTextAreaElement)
                                        .value,
                                })
                            "
                        />
                        <InputError
                            v-for="(message, i) in checks.errors[
                                `options.${index}`
                            ] ?? []"
                            :key="i"
                            :message="message"
                        />
                    </div>
                    <div class="flex items-center gap-1">
                        <Button
                            type="button"
                            size="icon-sm"
                            variant="ghost"
                            :disabled="disabled"
                            title="Move up"
                            @click="move(index, -1)"
                            ><GripVertical class="rotate-90"
                        /></Button>
                        <Button
                            type="button"
                            size="icon-sm"
                            variant="ghost"
                            class="text-destructive"
                            :disabled="disabled"
                            :title="`Remove option ${option.label}`"
                            :data-remove-option="option.label"
                            @click="remove(index)"
                            ><Trash2
                        /></Button>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-4 pl-9 text-sm">
                    <label
                        v-if="marksCorrectHere"
                        class="flex cursor-pointer items-center gap-2"
                    >
                        <Checkbox
                            :model-value="option.is_correct"
                            :disabled="disabled"
                            :data-correct="option.label"
                            @update:model-value="setCorrect(index, $event)"
                        />
                        Correct
                    </label>
                    <label
                        v-if="partialCredit && option.is_correct"
                        class="flex items-center gap-2"
                    >
                        <span class="text-muted-foreground"
                            >Share of marks</span
                        >
                        <Input
                            class="h-8 w-20"
                            type="number"
                            step="0.05"
                            min="0"
                            max="1"
                            :model-value="option.weight ?? ''"
                            :disabled="disabled"
                            @update:model-value="
                                patch(index, {
                                    weight:
                                        $event === '' ? null : Number($event),
                                })
                            "
                        />
                    </label>
                    <label class="flex cursor-pointer items-center gap-2">
                        <Checkbox
                            :model-value="option.is_position_locked"
                            :disabled="disabled"
                            @update:model-value="
                                patch(index, {
                                    is_position_locked: $event === true,
                                })
                            "
                        />
                        <Lock class="size-3" /> Keep in place when shuffled
                    </label>
                    <label class="flex flex-1 items-center gap-2">
                        <span class="text-muted-foreground shrink-0"
                            >Feedback</span
                        >
                        <Input
                            class="h-8"
                            :model-value="option.feedback ?? ''"
                            :disabled="disabled"
                            placeholder="Shown after the exam, optional"
                            @update:model-value="
                                patch(index, {
                                    feedback:
                                        $event === '' ? null : String($event),
                                })
                            "
                        />
                    </label>
                </div>
            </li>
            <li
                v-if="modelValue.length === 0"
                class="text-muted-foreground text-sm"
            >
                No options yet.
            </li>
        </ul>
    </section>
</template>
