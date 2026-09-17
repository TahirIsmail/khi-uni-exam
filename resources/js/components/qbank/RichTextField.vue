<script setup lang="ts">
import { ImagePlus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    id: string;
    label: string;
    modelValue: string | null;
    hint?: string;
    rows?: number;
    required?: boolean;
    disabled?: boolean;
    allowImages?: boolean;
    errors?: string[];
    counter?: { min?: number; max: number };
}>();

const emit = defineEmits<{ 'update:modelValue': [string | null] }>();

const field = ref<HTMLTextAreaElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const pending = ref<File | null>(null);
const altText = ref('');
const uploading = ref(false);
const uploadError = ref<string | null>(null);

// A rough count of what a reader sees: tags do not count towards the limit.
const plainLength = computed(
    () =>
        (props.modelValue ?? '')
            .replace(/<[^>]*>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim().length,
);

const tooLong = computed(
    () => props.counter !== undefined && plainLength.value > props.counter.max,
);

function csrf(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
}

function choose(): void {
    uploadError.value = null;
    fileInput.value?.click();
}

function fileChosen(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    pending.value = file;
    altText.value = '';
}

async function insertImage(): Promise<void> {
    if (!pending.value || altText.value.trim() === '') {
        return;
    }
    uploading.value = true;
    uploadError.value = null;

    try {
        const body = new FormData();
        body.append('file', pending.value);
        body.append('alt_text', altText.value.trim());

        const response = await fetch('/questions/media', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
            credentials: 'same-origin',
            body,
        });
        const payload = (await response.json()) as {
            url?: string;
            alt?: string;
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (!response.ok) {
            uploadError.value =
                Object.values(payload.errors ?? {}).flat()[0] ??
                payload.message ??
                'The picture could not be uploaded.';
            return;
        }

        const tag = `<img src="${payload.url}" alt="${(payload.alt ?? '').replace(/"/g, '&quot;')}">`;
        const element = field.value;
        const current = props.modelValue ?? '';
        const at = element?.selectionStart ?? current.length;
        emit(
            'update:modelValue',
            current.slice(0, at) + tag + current.slice(at),
        );

        pending.value = null;
        altText.value = '';
        if (fileInput.value) {
            fileInput.value.value = '';
        }
    } catch {
        uploadError.value = 'The picture could not be uploaded.';
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <div class="grid gap-1.5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <Label :for="id"
                >{{ label
                }}<span v-if="required" class="text-destructive">
                    *</span
                ></Label
            >
            <div class="flex items-center gap-3">
                <Button
                    v-if="allowImages && !disabled"
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="h-7 px-2"
                    :data-add-image="id"
                    @click="choose"
                >
                    <ImagePlus class="size-3.5" /> Picture
                </Button>
                <span
                    v-if="counter"
                    class="text-xs tabular-nums"
                    :class="
                        tooLong ? 'text-destructive' : 'text-muted-foreground'
                    "
                    >{{ plainLength }} / {{ counter.max }}</span
                >
            </div>
        </div>

        <textarea
            :id="id"
            ref="field"
            class="border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 min-h-24 rounded-md border px-3 py-2 font-mono text-[13px] leading-relaxed focus-visible:ring-[3px] focus-visible:outline-none disabled:opacity-50"
            :class="tooLong ? 'border-destructive' : ''"
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

        <input
            v-if="allowImages"
            ref="fileInput"
            type="file"
            accept="image/png,image/jpeg,image/webp"
            class="hidden"
            :data-file="id"
            @change="fileChosen"
        />

        <div
            v-if="pending"
            class="bg-muted/40 grid gap-2 rounded-md border p-3"
        >
            <div class="flex items-center justify-between gap-2 text-sm">
                <span class="truncate">{{ pending.name }}</span>
                <Button
                    type="button"
                    size="icon-sm"
                    variant="ghost"
                    title="Cancel"
                    @click="pending = null"
                >
                    <X />
                </Button>
            </div>
            <div class="grid gap-1.5">
                <Label :for="`${id}-alt`">Describe the picture *</Label>
                <Input
                    :id="`${id}-alt`"
                    v-model="altText"
                    maxlength="255"
                    placeholder="e.g. ECG showing ST elevation in leads II, III and aVF"
                />
                <p class="text-muted-foreground text-xs">
                    Needed for screen readers and for printed papers.
                </p>
            </div>
            <div>
                <Button
                    type="button"
                    size="sm"
                    :disabled="uploading || altText.trim() === ''"
                    :data-insert-image="id"
                    @click="insertImage"
                >
                    {{ uploading ? 'Uploading…' : 'Insert picture' }}
                </Button>
            </div>
        </div>

        <p v-if="uploadError" class="text-destructive text-sm">
            {{ uploadError }}
        </p>
        <p v-if="hint" class="text-muted-foreground text-xs">{{ hint }}</p>
        <InputError
            v-for="(message, i) in errors ?? []"
            :key="i"
            :message="message"
        />
    </div>
</template>
