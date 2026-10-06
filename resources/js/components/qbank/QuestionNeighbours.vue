<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import type { QuestionNeighbourLinks } from '@/types';

// Going from one question to the next without going back to the list.
defineProps<{ neighbours: QuestionNeighbourLinks }>();
</script>

<template>
    <nav
        v-if="neighbours.total > 0"
        class="flex flex-wrap items-center justify-between gap-2 border-t pt-4"
        data-test="neighbours"
    >
        <Button
            v-if="neighbours.previous"
            as-child
            variant="outline"
            data-test="previous"
        >
            <Link :href="neighbours.previous.url"
                ><ChevronLeft /> Previous ·
                {{ neighbours.previous.reference }}</Link
            >
        </Button>
        <span v-else />
        <span class="text-muted-foreground text-sm">{{
            neighbours.position
                ? `${neighbours.position} of ${neighbours.total} ${neighbours.label}`
                : `${neighbours.total} ${neighbours.label}`
        }}</span>
        <Button
            v-if="neighbours.next"
            as-child
            variant="outline"
            data-test="next"
        >
            <Link :href="neighbours.next.url"
                >Next · {{ neighbours.next.reference }} <ChevronRight
            /></Link>
        </Button>
        <span v-else />
    </nav>
</template>
