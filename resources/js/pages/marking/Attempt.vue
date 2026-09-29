<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import marking from '@/routes/marking';
import type { AttemptMarkSheet } from '@/types';

defineProps<{
    examination: { id: number; reference: string; title: string };
    attempt: AttemptMarkSheet;
    can: { mark: boolean };
}>();
</script>

<template>
    <Head :title="`${attempt.candidateNo} — ${examination.title}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`${attempt.candidateNo} — ${attempt.name}`"
            :description="`${examination.reference} · ${examination.title}`"
        />

        <p class="text-muted-foreground max-w-prose text-sm">
            Every question on this candidate's paper, and where each mark came
            from. An item marked by the computer can be opened and marked again
            — your mark then replaces it, and the original stays on record.
        </p>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Item</th>
                        <th class="px-3 py-2 font-medium">Type</th>
                        <th class="px-3 py-2 font-medium">Awarded</th>
                        <th class="px-3 py-2 font-medium">Marked by</th>
                        <th class="px-3 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in attempt.items"
                        :key="row.itemId"
                        class="border-t"
                        :data-item="row.itemId"
                    >
                        <td class="px-3 py-2">Q{{ row.position }}</td>
                        <td class="text-muted-foreground px-3 py-2">
                            {{ row.typeName ?? '—' }}
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            <span v-if="row.awaiting" class="text-muted-foreground"
                                >Awaiting</span
                            >
                            <span v-else>{{ row.awarded }} / {{ row.marks }}</span>
                        </td>
                        <td class="px-3 py-2">
                            <span class="text-muted-foreground">{{
                                row.sourceLabel ?? '—'
                            }}</span>
                            <Badge
                                v-if="row.isMachineMark"
                                variant="secondary"
                                class="ml-2"
                                >by computer</Badge
                            >
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                v-if="can.mark"
                                :href="
                                    marking.items.show([
                                        examination.id,
                                        row.itemId,
                                    ])
                                "
                                class="underline-offset-4 hover:underline"
                                data-test="open-item"
                                >Open</Link
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Link
            :href="marking.show(examination.id)"
            class="text-muted-foreground text-sm underline-offset-4 hover:underline"
            >Back to marking</Link
        >
    </div>
</template>
