<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    id: string;
    label: string;
    modelValue: string | null;
    hint?: string;
    rows?: number;
    required?: boolean;
    disabled?: boolean;
    errors?: string[];
    counter?: { min?: number; max: number };
}>();

const emit = defineEmits<{ 'update:modelValue': [string | null] }>();

// A rough count of what a reader sees: tags do not count towards the limit.
const plainLength = computed(
    () =>
        (props.modelValue ?? '')
            .replace(/<[^>]*>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim().length,
);
</script>

<template>
    <div class="grid gap-1.5">
        <div class="flex items-baseline justify-between gap-2">
            <Label :for="id"
                >{{ label
                }}<span v-if="required" class="text-destructive">
                    *</span
                ></Label
            >
            <span
                v-if="counter"
                class="text-xs"
                :class="
                    plainLength > counter.max ||
                    (counter.min !== undefined &&
                        plainLength > 0 &&
                        plainLength < counter.min)
                        ? 'text-destructive'
                        : 'text-muted-foreground'
                "
                >{{ plainLength }} / {{ counter.max }}</span
            >
        </div>
        <textarea
            :id="id"
            class="border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 min-h-24 rounded-md border px-3 py-2 text-sm focus-visible:ring-[3px] focus-visible:outline-none disabled:opacity-50"
            :rows="rows ?? 4"
            :value="modelValue ?? ''"
            :disabled="disabled"
            @input="
                emit(
                    'update:modelValue',
                    ($event.target as HTMLTextAreaElement).value || null,
                )
            "
        />
        <p v-if="hint" class="text-muted-foreground text-xs">{{ hint }}</p>
        <InputError
            v-for="(message, i) in errors ?? []"
            :key="i"
            :message="message"
        />
    </div>
</template>
