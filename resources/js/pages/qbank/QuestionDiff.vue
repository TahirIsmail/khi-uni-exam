<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/questions';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Question bank', href: index() }] },
});

type DiffPart = { type: string; text: string };
type TextDiff = { changed: boolean; parts: DiffPart[] };

const props = defineProps<{
    reference: string;
    questionId: number;
    versions: { id: number; versionNo: number; statusLabel: string }[];
    diff: {
        from: {
            id: number;
            versionNo: number;
            statusLabel: string;
            type: string;
            updatedAt: string | null;
        };
        to: {
            id: number;
            versionNo: number;
            statusLabel: string;
            type: string;
            updatedAt: string | null;
        };
        text: {
            vignette: TextDiff;
            stem: TextDiff;
            leadIn: TextDiff;
            explanation: TextDiff;
        };
        options: {
            label: string;
            state: string;
            text: TextDiff;
            wasCorrect: boolean | null;
            isCorrect: boolean | null;
            keyChanged: boolean;
        }[];
        facts: {
            label: string;
            before: string;
            after: string;
            changed: boolean;
        }[];
    };
}>();

const from = ref(props.diff.from.id);
const to = ref(props.diff.to.id);

const textFields: {
    key: 'vignette' | 'stem' | 'leadIn' | 'explanation';
    label: string;
}[] = [
    { key: 'vignette', label: 'Clinical scenario' },
    { key: 'stem', label: 'Question' },
    { key: 'leadIn', label: 'Lead-in' },
    { key: 'explanation', label: 'Explanation' },
];

function recompare(): void {
    router.get(`/questions/${props.questionId}/diff`, {
        from: from.value,
        to: to.value,
    });
}
</script>

<template>
    <Head :title="`${reference} — comparing versions`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`${reference}: version ${diff.from.versionNo} → version ${diff.to.versionNo}`"
                description="Words taken out are struck through in red; words put in are underlined in green."
            />
            <div class="flex flex-wrap items-center gap-2">
                <select
                    v-model.number="from"
                    class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                    aria-label="Compare from"
                >
                    <option
                        v-for="version in versions"
                        :key="version.id"
                        :value="version.id"
                    >
                        v{{ version.versionNo }} ({{ version.statusLabel }})
                    </option>
                </select>
                <select
                    v-model.number="to"
                    class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                    aria-label="Compare with"
                >
                    <option
                        v-for="version in versions"
                        :key="version.id"
                        :value="version.id"
                    >
                        v{{ version.versionNo }} ({{ version.statusLabel }})
                    </option>
                </select>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="from === to"
                    @click="recompare"
                >
                    Compare
                </Button>
                <Button as-child variant="ghost">
                    <Link :href="`/questions/${questionId}`"
                        ><ArrowLeft /> History</Link
                    >
                </Button>
            </div>
        </div>

        <section class="grid gap-4 rounded-xl border p-4 shadow-xs">
            <h2 class="font-medium">The question text</h2>
            <div
                v-for="field in textFields"
                :key="field.key"
                class="grid gap-1.5"
            >
                <div class="flex items-center gap-2">
                    <h3 class="text-sm font-medium">{{ field.label }}</h3>
                    <Badge
                        v-if="diff.text[field.key].changed"
                        variant="secondary"
                        >changed</Badge
                    >
                    <span v-else class="text-muted-foreground text-xs"
                        >unchanged</span
                    >
                </div>
                <p
                    v-if="diff.text[field.key].parts.length > 0"
                    class="text-sm leading-relaxed"
                    :data-diff="field.key"
                >
                    <template
                        v-for="(part, index) in diff.text[field.key].parts"
                        :key="index"
                    >
                        <span
                            v-if="part.type === 'removed'"
                            class="text-destructive bg-destructive/10 rounded px-0.5 line-through"
                            >{{ part.text }}</span
                        >
                        <span
                            v-else-if="part.type === 'added'"
                            class="rounded bg-green-100 px-0.5 text-green-800 underline dark:bg-green-950 dark:text-green-300"
                            >{{ part.text }}</span
                        >
                        <span v-else>{{ part.text }}</span>
                        {{ ' ' }}
                    </template>
                </p>
                <p v-else class="text-muted-foreground text-sm">
                    Empty in both versions.
                </p>
            </div>
        </section>

        <section
            v-if="diff.options.length > 0"
            class="rounded-xl border shadow-xs"
        >
            <header class="border-b px-4 py-3">
                <h2 class="font-medium">Options and the answer key</h2>
            </header>
            <ul class="divide-y" data-test="option-diff">
                <li
                    v-for="option in diff.options"
                    :key="option.label"
                    class="grid gap-2 px-4 py-3 sm:grid-cols-[3rem_1fr_auto] sm:items-start"
                >
                    <span class="font-medium">{{ option.label }}</span>
                    <p class="text-sm leading-relaxed">
                        <template
                            v-for="(part, index) in option.text.parts"
                            :key="index"
                        >
                            <span
                                v-if="part.type === 'removed'"
                                class="text-destructive bg-destructive/10 rounded px-0.5 line-through"
                                >{{ part.text }}</span
                            >
                            <span
                                v-else-if="part.type === 'added'"
                                class="rounded bg-green-100 px-0.5 text-green-800 underline dark:bg-green-950 dark:text-green-300"
                                >{{ part.text }}</span
                            >
                            <span v-else>{{ part.text }}</span>
                            {{ ' ' }}
                        </template>
                    </p>
                    <div class="flex flex-wrap items-center gap-1">
                        <Badge
                            v-if="option.state === 'added'"
                            variant="secondary"
                            >new option</Badge
                        >
                        <Badge
                            v-if="option.state === 'removed'"
                            variant="destructive"
                            >removed</Badge
                        >
                        <Badge
                            v-if="option.keyChanged"
                            variant="destructive"
                            :data-key-changed="option.label"
                        >
                            {{
                                option.isCorrect
                                    ? 'now the key'
                                    : 'no longer the key'
                            }}
                        </Badge>
                        <Badge v-else-if="option.isCorrect" variant="outline"
                            >key</Badge
                        >
                    </div>
                </li>
            </ul>
        </section>

        <section class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Detail</th>
                        <th class="px-3 py-2 font-medium">
                            v{{ diff.from.versionNo }}
                        </th>
                        <th class="px-3 py-2 font-medium">
                            v{{ diff.to.versionNo }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="fact in diff.facts"
                        :key="fact.label"
                        class="border-t"
                        :class="
                            fact.changed
                                ? 'bg-amber-50 dark:bg-amber-950/40'
                                : ''
                        "
                    >
                        <td class="px-3 py-2">{{ fact.label }}</td>
                        <td class="px-3 py-2">{{ fact.before }}</td>
                        <td class="px-3 py-2 font-medium">{{ fact.after }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
