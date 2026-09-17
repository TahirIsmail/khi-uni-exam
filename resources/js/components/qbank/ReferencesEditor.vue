<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { QuestionChecks, ReferenceRow } from '@/types';

const props = defineProps<{
    modelValue: ReferenceRow[];
    required: boolean;
    checks: QuestionChecks;
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [ReferenceRow[]] }>();

function update(rows: ReferenceRow[]): void {
    emit(
        'update:modelValue',
        rows.map((row, index) => ({ ...row, sort_order: index + 1 })),
    );
}

function patch(index: number, changes: Partial<ReferenceRow>): void {
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
                <h3 class="font-medium">
                    References<span v-if="required" class="text-destructive">
                        *</span
                    >
                </h3>
                <p class="text-muted-foreground text-xs">
                    Where the answer comes from. Reviewers check this first.
                </p>
            </div>
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="disabled"
                data-test="add-reference"
                @click="
                    update([
                        ...modelValue,
                        {
                            kind: 'book',
                            citation: '',
                            locator: null,
                            url: null,
                            sort_order: 0,
                        },
                    ])
                "
            >
                <Plus /> Add reference
            </Button>
        </header>

        <InputError
            v-for="(message, i) in checks.errors['references'] ?? []"
            :key="i"
            :message="message"
        />

        <ul class="grid gap-2">
            <li
                v-for="(row, index) in modelValue"
                :key="index"
                class="grid gap-2 rounded-md border p-3 sm:grid-cols-[8rem_1fr_10rem_auto]"
            >
                <select
                    class="border-input bg-background h-8 rounded-md border px-2 text-sm"
                    :value="row.kind"
                    :disabled="disabled"
                    :aria-label="`Reference type ${index + 1}`"
                    @change="
                        patch(index, {
                            kind: ($event.target as HTMLSelectElement)
                                .value as ReferenceRow['kind'],
                        })
                    "
                >
                    <option value="book">Book</option>
                    <option value="journal">Journal</option>
                    <option value="guideline">Guideline</option>
                    <option value="url">Website</option>
                    <option value="other">Other</option>
                </select>
                <Input
                    class="h-8"
                    :model-value="row.citation"
                    :disabled="disabled"
                    placeholder="Harrison, Principles of Internal Medicine"
                    :aria-label="`Reference ${index + 1}`"
                    @update:model-value="
                        patch(index, { citation: String($event) })
                    "
                />
                <Input
                    class="h-8"
                    :model-value="row.locator ?? ''"
                    :disabled="disabled"
                    placeholder="21st ed, p. 1875"
                    @update:model-value="
                        patch(index, {
                            locator: $event === '' ? null : String($event),
                        })
                    "
                />
                <Button
                    type="button"
                    size="icon-sm"
                    variant="ghost"
                    class="text-destructive"
                    :disabled="disabled"
                    :title="`Remove reference ${index + 1}`"
                    @click="update(modelValue.filter((_, i) => i !== index))"
                    ><Trash2
                /></Button>
                <Input
                    v-if="row.kind === 'url' || row.url !== null"
                    class="col-span-full h-8"
                    :model-value="row.url ?? ''"
                    :disabled="disabled"
                    placeholder="https://…"
                    @update:model-value="
                        patch(index, {
                            url: $event === '' ? null : String($event),
                        })
                    "
                />
                <InputError
                    v-for="(message, i) in checks.errors[
                        `references.${index}`
                    ] ?? []"
                    :key="i"
                    class="col-span-full"
                    :message="message"
                />
            </li>
        </ul>
    </section>
</template>
