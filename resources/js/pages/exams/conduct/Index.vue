<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Building2, Search, X } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import conduct from '@/routes/conduct';
import type { ExaminationListRow, Paginated } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Conduct Exam', href: conduct.index() }] },
});

const props = defineProps<{
    examinations: Paginated<ExaminationListRow>;
    filters: { search: string };
    canManageCentres: boolean;
}>();

const search = ref(props.filters.search);

function apply(): void {
    router.get(
        conduct.index.url(),
        search.value === '' ? {} : { search: search.value },
        { preserveState: true, replace: true },
    );
}

function clear(): void {
    search.value = '';
    router.get(conduct.index.url());
}
</script>

<template>
    <Head title="Conduct Exam" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Conduct Exam"
                description="Register candidates, allocate their seats, and check them in on the day."
            />
            <Button v-if="canManageCentres" as-child variant="outline">
                <Link :href="conduct.centres()"
                    ><Building2 /> Centres &amp; rooms</Link
                >
            </Button>
        </div>

        <form
            class="flex flex-wrap items-end gap-2 rounded-xl border p-4 shadow-xs"
            @submit.prevent="apply"
        >
            <div class="grid flex-1 gap-1.5" style="min-width: 16rem">
                <Label for="search">Search</Label>
                <Input
                    id="search"
                    v-model="search"
                    maxlength="100"
                    placeholder="Title or reference, e.g. EX-2026-0001"
                    data-test="conduct-search"
                />
            </div>
            <Button type="submit" variant="outline"><Search /> Search</Button>
            <Button
                v-if="filters.search !== ''"
                type="button"
                variant="ghost"
                @click="clear"
            >
                <X /> Clear
            </Button>
        </form>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Reference</th>
                        <th class="px-3 py-2 font-medium">Examination</th>
                        <th class="px-3 py-2 font-medium">Course</th>
                        <th class="px-3 py-2 font-medium">Date</th>
                        <th class="px-3 py-2 font-medium">Candidates</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in examinations.data"
                        :key="row.id"
                        class="hover:bg-muted/40 border-t align-top"
                        :data-exam="row.id"
                    >
                        <td
                            class="px-3 py-2 font-mono text-xs whitespace-nowrap"
                        >
                            {{ row.reference }}
                        </td>
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ row.title }}</div>
                            <div class="text-muted-foreground text-xs">
                                {{ row.programme }} · {{ row.year }} ·
                                {{ row.examType }}
                            </div>
                        </td>
                        <td class="px-3 py-2">{{ row.course }}</td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ row.startsAt ?? 'Not fixed yet' }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <Link
                                :href="conduct.candidates(row.id)"
                                class="underline-offset-4 hover:underline"
                                data-test="candidates-link"
                                >Candidates</Link
                            >
                            ·
                            <Link
                                :href="conduct.checkin(row.id)"
                                class="underline-offset-4 hover:underline"
                                data-test="checkin-link"
                                >Check-in</Link
                            >
                            ·
                            <Link
                                :href="conduct.monitor(row.id)"
                                class="underline-offset-4 hover:underline"
                                data-test="monitor-link"
                                >Monitor</Link
                            >
                            ·
                            <Link
                                :href="conduct.proctoring(row.id)"
                                class="underline-offset-4 hover:underline"
                                data-test="proctoring-link"
                                >Proctoring</Link
                            >
                        </td>
                    </tr>
                    <tr v-if="examinations.data.length === 0">
                        <td colspan="5" class="px-3 py-10 text-center">
                            <p class="font-medium">No examinations found</p>
                            <p class="text-muted-foreground mt-1 text-sm">
                                {{
                                    filters.search !== ''
                                        ? 'Nothing matches this search.'
                                        : 'Nothing has been set up in this campus yet.'
                                }}
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="examinations.last_page > 1"
            class="flex flex-wrap items-center gap-2 text-sm"
        >
            <span class="text-muted-foreground"
                >{{ examinations.from }}–{{ examinations.to }} of
                {{ examinations.total }}</span
            >
            <template v-for="(link, i) in examinations.links" :key="i">
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
                    >{{
                        link.label
                            .replace('&laquo;', '«')
                            .replace('&raquo;', '»')
                    }}</Link
                >
            </template>
        </nav>
    </div>
</template>
