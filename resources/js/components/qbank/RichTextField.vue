<script setup lang="ts">
import { ImagePlus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
const uploading = ref(false);
const uploadError = ref<string | null>(null);
// Where the cursor was when "Picture" was pressed: choosing a file takes the focus away.
const insertAt = ref<number | null>(null);

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

/** The pictures in the text, so the author sees them rather than their tags. */
const pictures = computed(() =>
    [...(props.modelValue ?? '').matchAll(/<img\b[^>]*>/gi)].map((match) => ({
        tag: match[0],
        src: /\bsrc="([^"]*)"/i.exec(match[0])?.[1] ?? '',
        alt: /\balt="([^"]*)"/i.exec(match[0])?.[1] ?? '',
    })),
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
    insertAt.value = field.value?.selectionStart ?? null;
    fileInput.value?.click();
}

/** "OSPE_demo-1.jpg" becomes "OSPE demo 1": the picture's description for screen readers and print. */
function describe(file: File): string {
    const name = file.name
        .replace(/\.[^.]+$/, '')
        .replace(/[_-]+/g, ' ')
        .trim();

    return (name === '' ? 'Picture' : name).slice(0, 255);
}

// Choosing a picture is all it takes: it is uploaded and put where the cursor was.
async function fileChosen(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (!file) {
        return;
    }
    uploading.value = true;
    uploadError.value = null;

    try {
        const body = new FormData();
        body.append('file', file);
        body.append('alt_text', describe(file));

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
        const current = props.modelValue ?? '';
        const at = Math.min(insertAt.value ?? current.length, current.length);
        emit(
            'update:modelValue',
            current.slice(0, at) + tag + current.slice(at),
        );
    } catch {
        uploadError.value = 'The picture could not be uploaded.';
    } finally {
        uploading.value = false;
        if (fileInput.value) {
            fileInput.value.value = '';
        }
    }
}

function removePicture(tag: string): void {
    const next = (props.modelValue ?? '').replace(tag, '');
    emit('update:modelValue', next.trim() === '' ? null : next);
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
                    :disabled="uploading"
                    :data-add-image="id"
                    @click="choose"
                >
                    <ImagePlus class="size-3.5" />
                    {{ uploading ? 'Uploading…' : 'Picture' }}
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
            v-if="pictures.length > 0"
            class="flex flex-wrap gap-2"
            :data-pictures="id"
        >
            <figure
                v-for="(picture, index) in pictures"
                :key="index"
                class="bg-muted/40 relative rounded-md border p-1"
            >
                <img
                    :src="picture.src"
                    :alt="picture.alt"
                    class="h-20 max-w-40 rounded object-contain"
                />
                <Button
                    v-if="!disabled"
                    type="button"
                    size="icon-sm"
                    variant="secondary"
                    class="absolute top-1 right-1 size-6"
                    title="Remove this picture"
                    :data-remove-image="index"
                    @click="removePicture(picture.tag)"
                >
                    <X class="size-3.5" />
                </Button>
            </figure>
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
