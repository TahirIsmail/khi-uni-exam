<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { QuestionDraft, QuestionTypeInfo } from '@/types';

const props = defineProps<{
    draft: QuestionDraft;
    type: QuestionTypeInfo | null;
    showAnswers?: boolean;
    /** The heading; a paper names each question by its number instead. */
    title?: string;
}>();

// What the candidate sees: the same sanitised HTML the server stored, without the key.
const shuffled = computed(() => {
    const options = [...props.draft.options];
    return props.draft.settings['shuffle_options'] === true &&
        !props.showAnswers
        ? options.sort(
              (a, b) =>
                  (a.is_position_locked ? 0 : Math.random() - 0.5) -
                  (b.is_position_locked ? 0 : 0),
          )
        : options;
});
</script>

<template>
    <div class="rounded-xl border shadow-xs" data-test="candidate-preview">
        <header
            class="flex items-center justify-between gap-2 border-b px-4 py-3"
        >
            <h3 class="font-medium">
                {{ title ?? 'As the candidate sees it' }}
            </h3>
            <Badge variant="outline"
                >{{ draft.marks }} mark{{ draft.marks === 1 ? '' : 's' }}</Badge
            >
        </header>
        <div
            class="grid gap-4 p-4 [&_img]:my-2 [&_img]:max-h-64 [&_img]:rounded-md [&_img]:border"
        >
            <!-- eslint-disable vue/no-v-html -- sanitised on the server before storing -->
            <div
                v-if="draft.vignette"
                class="prose-sm bg-muted/40 rounded-md p-3"
                v-html="draft.vignette"
            />
            <div class="text-sm leading-relaxed" v-html="draft.stem" />
            <p v-if="draft.lead_in" class="text-sm font-medium">
                {{ draft.lead_in }}
            </p>

            <ol
                v-if="type?.hasOptions && draft.options.length > 0"
                class="grid gap-2"
            >
                <li
                    v-for="option in shuffled"
                    :key="option.label"
                    class="flex items-start gap-2 rounded-md border p-2 text-sm"
                    :class="
                        showAnswers && option.is_correct
                            ? 'border-green-600 bg-green-50 dark:bg-green-950/40'
                            : ''
                    "
                >
                    <span class="text-muted-foreground w-5 shrink-0">{{
                        option.label
                    }}</span>
                    <span class="min-w-0 flex-1" v-html="option.body" />
                    <Badge
                        v-if="showAnswers && option.is_correct"
                        variant="secondary"
                        >key</Badge
                    >
                </li>
            </ol>

            <div
                v-if="type?.hasItems && draft.items.length > 0"
                class="grid gap-2"
            >
                <div
                    v-for="(item, index) in draft.items"
                    :key="index"
                    class="flex items-start gap-2 rounded-md border p-2 text-sm"
                >
                    <span class="text-muted-foreground w-5 shrink-0">{{
                        index + 1
                    }}</span>
                    <span class="min-w-0 flex-1" v-html="item.body" />
                    <span
                        v-if="type.itemAnswer === 'boolean'"
                        class="text-muted-foreground shrink-0"
                    >
                        <template v-if="showAnswers">{{
                            item.is_true ? 'True' : 'False'
                        }}</template>
                        <template v-else>True / False</template>
                    </span>
                    <span
                        v-else-if="type.itemAnswer === 'option'"
                        class="text-muted-foreground shrink-0"
                    >
                        {{
                            showAnswers
                                ? (item.correct_option_label ?? '—')
                                : '□'
                        }}
                    </span>
                    <span
                        v-else-if="type.itemAnswer === 'position'"
                        class="text-muted-foreground shrink-0"
                    >
                        {{ showAnswers ? `position ${index + 1}` : '↕' }}
                    </span>
                </div>
            </div>

            <div
                v-if="type?.hasAcceptedAnswers"
                class="text-muted-foreground rounded-md border border-dashed p-3 text-sm"
            >
                <template v-if="showAnswers">
                    Accepted:
                    {{
                        draft.answers
                            .map((answer) =>
                                answer.numeric_value !== null
                                    ? `${answer.numeric_value}${answer.tolerance ? ` ± ${answer.tolerance}` : ''}${answer.unit ? ` ${answer.unit}` : ''}`
                                    : (answer.answer_text ?? ''),
                            )
                            .join(', ')
                    }}
                </template>
                <template v-else>The candidate types the answer here.</template>
            </div>

            <div
                v-if="type?.isManuallyMarked"
                class="text-muted-foreground rounded-md border border-dashed p-3 text-sm"
            >
                Written answer{{
                    draft.settings['max_words']
                        ? `, up to ${draft.settings['max_words']} words`
                        : ''
                }}.
            </div>

            <div
                v-if="showAnswers && draft.explanation"
                class="grid gap-1 text-sm"
            >
                <h4 class="font-medium">Explanation</h4>
                <div v-html="draft.explanation" />
            </div>

            <ul
                v-if="showAnswers && draft.references.length > 0"
                class="text-muted-foreground grid gap-1 text-xs"
            >
                <li v-for="(reference, index) in draft.references" :key="index">
                    {{ reference.citation
                    }}<template v-if="reference.locator"
                        >, {{ reference.locator }}</template
                    >
                </li>
            </ul>
        </div>
    </div>
</template>
