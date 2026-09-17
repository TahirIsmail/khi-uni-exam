<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { QuestionTypeInfo, SettingMap, SettingValue } from '@/types';

const props = defineProps<{
    modelValue: SettingMap;
    type: QuestionTypeInfo;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [SettingMap];
}>();

// The settings a type offers come from the database, so a new type needs no new screen.
function labelFor(key: string): string {
    const words = key.replace(/_/g, ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
}

function patch(key: string, value: SettingValue): void {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}

function current(key: string): SettingValue {
    return props.modelValue[key] ?? props.type.defaultSettings[key];
}
</script>

<template>
    <section class="grid gap-3 rounded-lg border p-4">
        <h3 class="font-medium">Settings for this question</h3>
        <div class="grid gap-3 sm:grid-cols-2">
            <template
                v-for="(fallback, key) in type.defaultSettings"
                :key="key"
            >
                <label
                    v-if="typeof fallback === 'boolean'"
                    class="flex cursor-pointer items-center gap-2 text-sm"
                >
                    <Checkbox
                        :model-value="current(key) === true"
                        :disabled="disabled"
                        :data-setting="key"
                        @update:model-value="patch(key, $event === true)"
                    />
                    {{ labelFor(key) }}
                </label>

                <div
                    v-else-if="
                        typeof fallback === 'number' || fallback === null
                    "
                    class="grid gap-1.5"
                >
                    <Label :for="`setting-${key}`">{{ labelFor(key) }}</Label>
                    <Input
                        :id="`setting-${key}`"
                        type="number"
                        class="h-8"
                        :model-value="(current(key) as number | null) ?? ''"
                        :disabled="disabled"
                        :data-setting="key"
                        @update:model-value="
                            patch(key, $event === '' ? null : Number($event))
                        "
                    />
                </div>

                <div
                    v-else-if="typeof fallback === 'string'"
                    class="grid gap-1.5"
                >
                    <Label :for="`setting-${key}`">{{ labelFor(key) }}</Label>
                    <Input
                        :id="`setting-${key}`"
                        class="h-8"
                        :model-value="String(current(key) ?? '')"
                        :disabled="disabled"
                        :data-setting="key"
                        @update:model-value="patch(key, String($event))"
                    />
                </div>
            </template>
        </div>
    </section>
</template>
