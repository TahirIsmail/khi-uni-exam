<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Paginated } from '@/types';

defineProps<{
    page: Paginated<unknown>;
}>();

// Laravel labels the arrows with HTML entities; show plain text instead of rendering HTML.
function label(text: string): string {
    return text.replace('&laquo;', '«').replace('&raquo;', '»');
}
</script>

<template>
    <nav
        v-if="page.last_page > 1"
        class="flex flex-wrap items-center justify-between gap-2 text-sm"
        aria-label="Pagination"
    >
        <p class="text-muted-foreground">
            {{ page.from }}–{{ page.to }} of {{ page.total }}
        </p>
        <div class="flex flex-wrap gap-1">
            <template v-for="(link, i) in page.links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md border px-3 py-1"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-accent'
                    "
                    >{{ label(link.label) }}</Link
                >
                <span
                    v-else
                    class="text-muted-foreground rounded-md border px-3 py-1 opacity-50"
                    >{{ label(link.label) }}</span
                >
            </template>
        </div>
    </nav>
</template>
