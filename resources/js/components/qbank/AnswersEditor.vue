<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type {
    AnswerRow,
    ItemRow,
    QuestionChecks,
    QuestionTypeInfo,
} from '@/types';

const props = defineProps<{
    modelValue: AnswerRow[];
    items: ItemRow[];
    type: QuestionTypeInfo;
    checks: QuestionChecks;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [AnswerRow[]] }>();

function update(rows: AnswerRow[]): void {
    emit(
        'update:modelValue',
        rows.map((row, index) => ({ ...row, sort_order: index + 1 })),
    );
}

function add(): void {
    update([
        ...props.modelValue,
        {
            item_index: props.type.itemAnswer === 'text' ? 0 : null,
            match_mode: props.type.hasNumericAnswer ? 'numeric' : 'exact',
            answer_text: null,
            case_sensitive: false,
            numeric_value: null,
            tolerance: props.type.hasNumericAnswer ? 0 : null,
            tolerance_type: 'absolute',
            unit: null,
            marks_fraction: 1,
            feedback: null,
            sort_order: 0,
        },
    ]);
}

function patch(index: number, changes: Partial<AnswerRow>): void {
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
                <h3 class="font-medium">Accepted answers</h3>
                <p class="text-muted-foreground text-xs">
                    {{
                        type.hasNumericAnswer
                            ? 'A number with a tolerance, and its unit if one is expected.'
                            : 'Every spelling or wording that should be marked right.'
                    }}
                </p>
            </div>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="disabled"
                data-test="add-answer"
                @click="add"
            >
                <Plus /> Add answer
            </Button>
        </header>

        <InputError
            v-for="(message, i) in checks.errors['answers'] ?? []"
            :key="i"
            :message="message"
        />

        <ul class="grid gap-2">
            <li
                v-for="(answer, index) in modelValue"
                :key="index"
                class="grid gap-2 rounded-md border p-3"
                :data-answer="index"
            >
                <div class="flex flex-wrap items-center gap-3">
                    <label
                        v-if="type.itemAnswer === 'text'"
                        class="flex items-center gap-2 text-sm"
                    >
                        <span class="text-muted-foreground">Blank</span>
                        <select
                            class="border-input bg-background h-8 rounded-md border px-2 text-sm"
                            :value="answer.item_index ?? ''"
                            :disabled="disabled"
                            @change="
                                patch(index, {
                                    item_index:
                                        ($event.target as HTMLSelectElement)
                                            .value === ''
                                            ? null
                                            : Number(
                                                  (
                                                      $event.target as HTMLSelectElement
                                                  ).value,
                                              ),
                                })
                            "
                        >
                            <option
                                v-for="(item, i) in items"
                                :key="i"
                                :value="i"
                            >
                                {{ i + 1 }}
                            </option>
                        </select>
                    </label>

                    <template
                        v-if="
                            type.hasNumericAnswer ||
                            answer.match_mode === 'numeric'
                        "
                    >
                        <label class="flex items-center gap-2 text-sm">
                            <span class="text-muted-foreground">Value</span>
                            <Input
                                class="h-8 w-28"
                                type="number"
                                step="any"
                                :model-value="answer.numeric_value ?? ''"
                                :disabled="disabled"
                                :data-value="index"
                                @update:model-value="
                                    patch(index, {
                                        numeric_value:
                                            $event === ''
                                                ? null
                                                : Number($event),
                                    })
                                "
                            />
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <span class="text-muted-foreground">±</span>
                            <Input
                                class="h-8 w-24"
                                type="number"
                                step="any"
                                min="0"
                                :model-value="answer.tolerance ?? ''"
                                :disabled="disabled"
                                @update:model-value="
                                    patch(index, {
                                        tolerance:
                                            $event === ''
                                                ? null
                                                : Number($event),
                                    })
                                "
                            />
                            <select
                                class="border-input bg-background h-8 rounded-md border px-2 text-sm"
                                :value="answer.tolerance_type"
                                :disabled="disabled"
                                @change="
                                    patch(index, {
                                        tolerance_type: (
                                            $event.target as HTMLSelectElement
                                        ).value as 'absolute' | 'relative',
                                    })
                                "
                            >
                                <option value="absolute">absolute</option>
                                <option value="relative">%</option>
                            </select>
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <span class="text-muted-foreground">Unit</span>
                            <Input
                                class="h-8 w-24"
                                :model-value="answer.unit ?? ''"
                                :disabled="disabled"
                                placeholder="mmol/L"
                                @update:model-value="
                                    patch(index, {
                                        unit:
                                            $event === ''
                                                ? null
                                                : String($event),
                                    })
                                "
                            />
                        </label>
                    </template>

                    <template v-else>
                        <Input
                            class="h-8 flex-1"
                            :model-value="answer.answer_text ?? ''"
                            :disabled="disabled"
                            placeholder="Accepted answer"
                            :aria-label="`Accepted answer ${index + 1}`"
                            :data-text="index"
                            @update:model-value="
                                patch(index, {
                                    answer_text:
                                        $event === '' ? null : String($event),
                                })
                            "
                        />
                        <select
                            class="border-input bg-background h-8 rounded-md border px-2 text-sm"
                            :value="answer.match_mode"
                            :disabled="disabled"
                            @change="
                                patch(index, {
                                    match_mode: (
                                        $event.target as HTMLSelectElement
                                    ).value as AnswerRow['match_mode'],
                                })
                            "
                        >
                            <option value="exact">exactly this</option>
                            <option value="contains">contains this</option>
                            <option value="regex">pattern</option>
                        </select>
                        <label
                            class="flex cursor-pointer items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="answer.case_sensitive"
                                :disabled="disabled"
                                @update:model-value="
                                    patch(index, {
                                        case_sensitive: $event === true,
                                    })
                                "
                            />
                            Case matters
                        </label>
                    </template>

                    <label class="flex items-center gap-2 text-sm">
                        <span class="text-muted-foreground">Marks</span>
                        <Input
                            class="h-8 w-20"
                            type="number"
                            step="0.05"
                            min="0"
                            max="1"
                            :model-value="answer.marks_fraction"
                            :disabled="disabled"
                            @update:model-value="
                                patch(index, { marks_fraction: Number($event) })
                            "
                        />
                    </label>

                    <Button
                        type="button"
                        size="icon-sm"
                        variant="ghost"
                        class="text-destructive ml-auto"
                        :disabled="disabled"
                        :title="`Remove answer ${index + 1}`"
                        @click="
                            update(modelValue.filter((_, i) => i !== index))
                        "
                        ><Trash2
                    /></Button>
                </div>
                <InputError
                    v-for="(message, i) in checks.errors[`answers.${index}`] ??
                    []"
                    :key="i"
                    :message="message"
                />
            </li>
        </ul>
    </section>
</template>
