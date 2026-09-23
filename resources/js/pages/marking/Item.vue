<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import marking from '@/routes/marking';
import type {
    CandidateAnswerPayload,
    MarkableItem,
    RecordedMark,
} from '@/types';

const props = defineProps<{
    examination: { id: number; title: string };
    item: MarkableItem;
    answer: CandidateAnswerPayload | null;
    marks: RecordedMark[];
}>();

const optionsById = computed(() =>
    Object.fromEntries(props.item.options.map((o) => [o.id, o])),
);
const itemsById = computed(() =>
    Object.fromEntries(props.item.items.map((i) => [i.id, i])),
);

const criteriaMarks = useForm<Record<number, number | undefined>>(
    Object.fromEntries(props.item.rubricCriteria.map((c) => [c.id, undefined])),
);
const flatMark = useForm<{ marks_awarded: number | undefined; comments: string }>({
    marks_awarded: undefined,
    comments: '',
});

const criteriaTotal = computed(() =>
    props.item.rubricCriteria.reduce(
        (sum, c) => sum + (Number(criteriaMarks[c.id]) || 0),
        0,
    ),
);

function submit(): void {
    const criteria =
        props.item.rubricCriteria.length > 0
            ? props.item.rubricCriteria.map((c) => ({
                  rubric_criterion_id: c.id,
                  marks_awarded: Number(criteriaMarks[c.id]) || 0,
              }))
            : [];
    const marksAwarded =
        props.item.rubricCriteria.length > 0
            ? criteriaTotal.value
            : Number(flatMark.marks_awarded) || 0;

    flatMark
        .transform((data) => ({
            marks_awarded: marksAwarded,
            comments: data.comments,
            criteria,
        }))
        .post(marking.items.mark([props.examination.id, props.item.id]).url);
}
</script>

<template>
    <Head :title="`Mark Q${item.position} — ${examination.title}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Mark Q${item.position} — ${examination.title}`"
            :description="`${item.marks} marks`"
        />

        <div class="grid gap-4 rounded-xl border p-4 shadow-xs">
            <p v-if="item.vignette" class="text-sm" v-html="item.vignette" />
            <p class="text-sm leading-relaxed" v-html="item.stem" />
            <p v-if="item.leadIn" class="text-sm font-medium">
                {{ item.leadIn }}
            </p>

            <div v-if="item.options.length > 0" class="grid gap-1">
                <div
                    v-for="option in item.options"
                    :key="option.id"
                    class="flex items-center gap-2 rounded-md border p-2 text-sm"
                    :class="
                        answer?.selected?.includes(option.id)
                            ? 'border-primary bg-accent'
                            : ''
                    "
                >
                    <span class="text-muted-foreground w-5">{{
                        option.label
                    }}</span>
                    <span v-html="option.body" />
                    <Badge
                        v-if="answer?.selected?.includes(option.id)"
                        variant="secondary"
                        class="ml-auto"
                        >Chosen</Badge
                    >
                </div>
            </div>

            <div v-if="item.items.length > 0" class="grid gap-1">
                <div
                    v-for="sub in item.items"
                    :key="sub.id"
                    class="flex items-center justify-between gap-2 rounded-md border p-2 text-sm"
                >
                    <span v-html="sub.body" />
                    <span class="text-muted-foreground">
                        <template v-if="typeof answer?.items?.[sub.id] === 'boolean'">
                            {{ answer.items[sub.id] ? 'True' : 'False' }}
                        </template>
                        <template v-else-if="answer?.items?.[sub.id] !== undefined">
                            {{ optionsById[Number(answer.items[sub.id])]?.body ?? '—' }}
                        </template>
                        <template v-else>No answer</template>
                    </span>
                </div>
            </div>

            <ol v-if="answer?.order" class="grid list-decimal gap-1 pl-5 text-sm">
                <li v-for="id in answer.order" :key="id" v-html="itemsById[id]?.body" />
            </ol>

            <p v-if="answer?.text" class="rounded-md border p-2 text-sm whitespace-pre-wrap">
                {{ answer.text }}
            </p>
            <p v-if="!answer" class="text-muted-foreground text-sm">
                No answer was given.
            </p>
        </div>

        <div v-if="marks.length > 0" class="grid gap-2">
            <h2 class="text-sm font-medium">Marks recorded so far</h2>
            <div
                v-for="mark in marks"
                :key="mark.source"
                class="rounded-lg border p-3 text-sm"
                :data-mark="mark.source"
            >
                <span class="font-medium">{{ mark.sourceLabel }}:</span>
                {{ mark.marksAwarded }} / {{ item.marks }}
            </div>
        </div>

        <form
            class="grid max-w-md gap-3 rounded-xl border p-4 shadow-xs"
            data-test="mark-form"
            @submit.prevent="submit"
        >
            <h2 class="text-sm font-medium">Your mark</h2>

            <div v-if="item.rubricCriteria.length > 0" class="grid gap-2">
                <div
                    v-for="criterion in item.rubricCriteria"
                    :key="criterion.id"
                    class="grid gap-1"
                >
                    <Label :for="`criterion-${criterion.id}`"
                        >{{ criterion.criterion }} (max
                        {{ criterion.maxMarks }})</Label
                    >
                    <p
                        v-if="criterion.guidance"
                        class="text-muted-foreground text-xs"
                    >
                        {{ criterion.guidance }}
                    </p>
                    <Input
                        :id="`criterion-${criterion.id}`"
                        v-model="criteriaMarks[criterion.id]"
                        type="number"
                        step="0.5"
                        min="0"
                        :max="criterion.maxMarks"
                        :data-test="`criterion-${criterion.id}`"
                    />
                </div>
                <p class="text-sm font-medium">
                    Total: {{ criteriaTotal }} / {{ item.marks }}
                </p>
            </div>
            <div v-else class="grid gap-1.5">
                <Label for="marks-awarded">Marks awarded</Label>
                <Input
                    id="marks-awarded"
                    v-model="flatMark.marks_awarded"
                    type="number"
                    step="0.5"
                    min="0"
                    :max="item.marks"
                    data-test="marks-awarded"
                />
            </div>

            <div class="grid gap-1.5">
                <Label for="comments">Comments (optional)</Label>
                <Input
                    id="comments"
                    v-model="flatMark.comments"
                    maxlength="500"
                    data-test="comments"
                />
            </div>

            <Button
                type="submit"
                class="justify-self-start"
                :disabled="flatMark.processing"
                data-test="save-mark"
                >Record mark</Button
            >
        </form>
    </div>
</template>
