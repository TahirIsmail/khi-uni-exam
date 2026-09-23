<script setup lang="ts">
import { ArrowDown, ArrowUp, Flag } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { AnswerPayload, AttemptItem } from '@/types';

const props = defineProps<{
    item: AttemptItem;
    answer: AnswerPayload | null;
    flagged: boolean;
}>();

const emit = defineEmits<{
    change: [payload: AnswerPayload];
    flag: [flagged: boolean];
}>();

const selected = computed<number[]>(() => props.answer?.selected ?? []);
const itemAnswers = computed<Record<number, boolean | number>>(
    () => props.answer?.items ?? {},
);
const order = computed<number[]>(
    () => props.answer?.order ?? props.item.items.map((i) => i.id),
);
const text = computed(() => props.answer?.text ?? '');

function toggleOption(optionId: number): void {
    const isSingle = props.item.correctMax === 1;
    if (isSingle) {
        emit('change', { selected: [optionId] });

        return;
    }
    const current = selected.value;
    const next = current.includes(optionId)
        ? current.filter((id) => id !== optionId)
        : [...current, optionId];
    emit('change', { selected: next });
}

function setItemAnswer(itemId: number, value: boolean | number): void {
    emit('change', { items: { ...itemAnswers.value, [itemId]: value } });
}

function setText(value: string): void {
    emit('change', { text: value });
}

function move(index: number, direction: -1 | 1): void {
    const next = [...order.value];
    const target = index + direction;
    if (target < 0 || target >= next.length) {
        return;
    }
    [next[index], next[target]] = [next[target], next[index]];
    emit('change', { order: next });
}

const orderedSubItems = computed(() =>
    order.value
        .map((id) => props.item.items.find((i) => i.id === id))
        .filter((i): i is (typeof props.item.items)[number] => i !== undefined),
);
</script>

<template>
    <div class="rounded-xl border shadow-xs" data-test="answer-capture">
        <header
            class="flex items-center justify-between gap-2 border-b px-4 py-3"
        >
            <h3 class="font-medium">Question {{ item.position }}</h3>
            <div class="flex items-center gap-2">
                <Badge variant="outline"
                    >{{ item.marks }} mark{{
                        item.marks === 1 ? '' : 's'
                    }}</Badge
                >
                <Button
                    size="sm"
                    :variant="flagged ? 'default' : 'outline'"
                    data-test="flag-item"
                    @click="emit('flag', !flagged)"
                >
                    <Flag /> {{ flagged ? 'Flagged' : 'Flag' }}
                </Button>
            </div>
        </header>
        <div
            class="grid gap-4 p-4 [&_img]:my-2 [&_img]:max-h-64 [&_img]:rounded-md [&_img]:border"
        >
            <!-- eslint-disable vue/no-v-html -- sanitised on the server before storing -->
            <div
                v-if="item.vignette"
                class="prose-sm bg-muted/40 rounded-md p-3"
                v-html="item.vignette"
            />
            <div class="text-sm leading-relaxed" v-html="item.stem" />
            <p v-if="item.leadIn" class="text-sm font-medium">
                {{ item.leadIn }}
            </p>

            <!-- Single or multiple choice from a list of options. -->
            <ol
                v-if="item.hasOptions && item.itemAnswer === 'none'"
                class="grid gap-2"
            >
                <li v-for="option in item.options" :key="option.id">
                    <label
                        class="hover:bg-accent flex cursor-pointer items-start gap-2 rounded-md border p-2 text-sm"
                        :class="
                            selected.includes(option.id)
                                ? 'border-primary bg-accent'
                                : ''
                        "
                    >
                        <input
                            :type="item.correctMax === 1 ? 'radio' : 'checkbox'"
                            :name="`item-${item.id}`"
                            class="mt-0.5"
                            :checked="selected.includes(option.id)"
                            :data-test="`option-${option.label}`"
                            @change="toggleOption(option.id)"
                        />
                        <span class="text-muted-foreground w-5 shrink-0">{{
                            option.label
                        }}</span>
                        <span class="min-w-0 flex-1" v-html="option.body" />
                    </label>
                </li>
            </ol>

            <!-- One true/false choice per statement. -->
            <div
                v-else-if="item.hasItems && item.itemAnswer === 'boolean'"
                class="grid gap-2"
            >
                <div
                    v-for="sub in item.items"
                    :key="sub.id"
                    class="flex items-center gap-3 rounded-md border p-2 text-sm"
                >
                    <span class="min-w-0 flex-1" v-html="sub.body" />
                    <label class="flex items-center gap-1">
                        <input
                            type="radio"
                            :name="`sub-${sub.id}`"
                            :checked="itemAnswers[sub.id] === true"
                            :data-test="`item-${sub.id}-true`"
                            @change="setItemAnswer(sub.id, true)"
                        />
                        True
                    </label>
                    <label class="flex items-center gap-1">
                        <input
                            type="radio"
                            :name="`sub-${sub.id}`"
                            :checked="itemAnswers[sub.id] === false"
                            :data-test="`item-${sub.id}-false`"
                            @change="setItemAnswer(sub.id, false)"
                        />
                        False
                    </label>
                </div>
            </div>

            <!-- Matching: each statement gets one of the options. -->
            <div
                v-else-if="item.hasItems && item.itemAnswer === 'option'"
                class="grid gap-2"
            >
                <div
                    v-for="sub in item.items"
                    :key="sub.id"
                    class="flex items-center gap-3 rounded-md border p-2 text-sm"
                >
                    <span class="min-w-0 flex-1" v-html="sub.body" />
                    <select
                        class="border-input bg-background h-8 min-w-32 rounded-md border px-2 text-sm"
                        :data-test="`item-${sub.id}-option`"
                        :value="itemAnswers[sub.id] ?? ''"
                        @change="
                            setItemAnswer(
                                sub.id,
                                Number(
                                    ($event.target as HTMLSelectElement).value,
                                ),
                            )
                        "
                    >
                        <option value="" disabled>Choose…</option>
                        <option
                            v-for="option in item.options"
                            :key="option.id"
                            :value="option.id"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Put the statements in order. -->
            <ol
                v-else-if="item.hasItems && item.itemAnswer === 'position'"
                class="grid gap-2"
            >
                <li
                    v-for="(sub, index) in orderedSubItems"
                    :key="sub.id"
                    class="flex items-center gap-2 rounded-md border p-2 text-sm"
                    :data-test="`order-${sub.id}`"
                >
                    <span class="text-muted-foreground w-5 shrink-0">{{
                        index + 1
                    }}</span>
                    <span class="min-w-0 flex-1" v-html="sub.body" />
                    <Button
                        size="sm"
                        variant="ghost"
                        :disabled="index === 0"
                        data-test="move-up"
                        @click="move(index, -1)"
                        ><ArrowUp class="size-3"
                    /></Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        :disabled="index === orderedSubItems.length - 1"
                        data-test="move-down"
                        @click="move(index, 1)"
                        ><ArrowDown class="size-3"
                    /></Button>
                </li>
            </ol>

            <!-- A short typed answer, or an essay. -->
            <textarea
                v-else-if="item.hasAcceptedAnswers || item.isManuallyMarked"
                class="border-input bg-background min-h-24 rounded-md border p-2 text-sm"
                :value="text"
                data-test="text-answer"
                @input="setText(($event.target as HTMLTextAreaElement).value)"
            />
        </div>
    </div>
</template>
