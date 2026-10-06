<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type {
    ItemRow,
    OptionRow,
    QuestionChecks,
    QuestionTypeInfo,
} from '@/types';

const props = defineProps<{
    modelValue: ItemRow[];
    options: OptionRow[];
    type: QuestionTypeInfo;
    checks: QuestionChecks;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [ItemRow[]] }>();

const heading = computed(() => {
    switch (props.type.itemAnswer) {
        case 'boolean':
            return {
                title: 'Statements',
                add: 'Add statement',
                hint: 'Each statement is marked true or false.',
            };
        case 'option':
            return {
                title: 'Parts',
                add: 'Add part',
                hint: 'Each part is matched to one of the options above.',
            };
        case 'text':
            return {
                title: 'Blanks',
                add: 'Add blank',
                hint: 'Each blank is typed in; add its accepted answers below.',
            };
        case 'position':
            return {
                title: 'Steps',
                add: 'Add step',
                hint: 'The order here is the correct order; candidates see them shuffled.',
            };
        default:
            return { title: 'Parts', add: 'Add part', hint: '' };
    }
});

function update(rows: ItemRow[]): void {
    emit(
        'update:modelValue',
        rows.map((row, index) => ({ ...row, sort_order: index + 1 })),
    );
}

function add(): void {
    if (props.modelValue.length >= props.type.itemsMax) {
        return;
    }
    update([
        ...props.modelValue,
        {
            body: '',
            is_true: null,
            correct_option_label: null,
            marks_fraction: null,
            feedback: null,
            sort_order: 0,
            settings: null,
        },
    ]);
}

function patch(index: number, changes: Partial<ItemRow>): void {
    update(
        props.modelValue.map((row, i) =>
            i === index ? { ...row, ...changes } : row,
        ),
    );
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
</script>

<template>
    <section class="grid gap-3 rounded-lg border p-4">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="font-medium">{{ heading.title }}</h3>
                <p class="text-muted-foreground text-xs">
                    {{ type.itemsMin }}–{{ type.itemsMax }}. {{ heading.hint }}
                </p>
            </div>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="disabled || modelValue.length >= type.itemsMax"
                data-test="add-item"
                @click="add"
            >
                <Plus /> {{ heading.add }}
            </Button>
        </header>

        <InputError
            v-for="(message, i) in checks.errors['items'] ?? []"
            :key="i"
            :message="message"
        />

        <ol class="grid gap-2">
            <li
                v-for="(item, index) in modelValue"
                :key="index"
                class="grid gap-2 rounded-md border p-3"
                :data-item="index"
            >
                <div class="flex items-start gap-2">
                    <span class="text-muted-foreground mt-2 w-5 text-sm"
                        >{{ index + 1 }}.</span
                    >
                    <div class="grid flex-1 gap-1.5">
                        <textarea
                            class="border-input bg-background min-h-9 rounded-md border px-3 py-2 text-sm"
                            rows="1"
                            :value="item.body"
                            :disabled="disabled"
                            :aria-label="`${heading.title} ${index + 1}`"
                            @input="
                                patch(index, {
                                    body: ($event.target as HTMLTextAreaElement)
                                        .value,
                                })
                            "
                        />
                        <InputError
                            v-for="(message, i) in checks.errors[
                                `items.${index}`
                            ] ?? []"
                            :key="i"
                            :message="message"
                        />
                    </div>
                    <div class="flex items-center gap-1">
                        <Button
                            v-if="type.itemAnswer === 'position'"
                            type="button"
                            size="icon-sm"
                            variant="ghost"
                            :disabled="disabled"
                            title="Move up"
                            @click="move(index, -1)"
                            ><ArrowUp
                        /></Button>
                        <Button
                            v-if="type.itemAnswer === 'position'"
                            type="button"
                            size="icon-sm"
                            variant="ghost"
                            :disabled="disabled"
                            title="Move down"
                            @click="move(index, 1)"
                            ><ArrowDown
                        /></Button>
                        <Button
                            type="button"
                            size="icon-sm"
                            variant="ghost"
                            class="text-destructive"
                            :disabled="disabled"
                            :title="`Remove ${index + 1}`"
                            @click="
                                update(modelValue.filter((_, i) => i !== index))
                            "
                            ><Trash2
                        /></Button>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-4 pl-7 text-sm">
                    <template v-if="type.itemAnswer === 'boolean'">
                        <div class="flex items-center gap-2">
                            <span class="text-muted-foreground">Answer</span>
                            <label
                                class="flex cursor-pointer items-center gap-1"
                            >
                                <input
                                    type="radio"
                                    :name="`item-${index}-truth`"
                                    :checked="item.is_true === true"
                                    :disabled="disabled"
                                    :data-true="index"
                                    @change="patch(index, { is_true: true })"
                                />
                                True
                            </label>
                            <label
                                class="flex cursor-pointer items-center gap-1"
                            >
                                <input
                                    type="radio"
                                    :name="`item-${index}-truth`"
                                    :checked="item.is_true === false"
                                    :disabled="disabled"
                                    :data-false="index"
                                    @change="patch(index, { is_true: false })"
                                />
                                False
                            </label>
                        </div>
                    </template>

                    <label
                        v-else-if="type.itemAnswer === 'option'"
                        class="flex items-center gap-2"
                    >
                        <span class="text-muted-foreground"
                            >Matching answer</span
                        >
                        <select
                            class="border-input bg-background h-8 rounded-md border px-2 text-sm"
                            :value="item.correct_option_label ?? ''"
                            :disabled="disabled"
                            :data-match="index"
                            @change="
                                patch(index, {
                                    correct_option_label:
                                        ($event.target as HTMLSelectElement)
                                            .value || null,
                                })
                            "
                        >
                            <option value="">Choose…</option>
                            <option
                                v-for="option in options"
                                :key="option.label"
                                :value="option.label"
                            >
                                {{ option.label }} —
                                {{
                                    option.body
                                        .replace(/<[^>]*>/g, '')
                                        .slice(0, 40)
                                }}
                            </option>
                        </select>
                    </label>

                    <label class="flex items-center gap-2">
                        <span class="text-muted-foreground"
                            >Share of marks</span
                        >
                        <Input
                            class="h-8 w-20"
                            type="number"
                            step="0.05"
                            min="0"
                            max="1"
                            :model-value="item.marks_fraction ?? ''"
                            :disabled="disabled"
                            @update:model-value="
                                patch(index, {
                                    marks_fraction:
                                        $event === '' ? null : Number($event),
                                })
                            "
                        />
                    </label>
                </div>
            </li>
        </ol>
    </section>
</template>
