<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index, show } from '@/routes/admin/staff';
import type { Paginated, StaffRow } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Staff scopes', href: index() }],
    },
});

const props = defineProps<{
    staff: Paginated<StaffRow>;
    search: string;
}>();

const term = ref(props.search);

function submit(): void {
    router.get(index.url(), term.value ? { search: term.value } : {}, {
        preserveState: true,
        replace: true,
    });
}
</script>

<template>
    <Head title="Staff scopes" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Staff scopes"
            description="Active kmu-cms staff in your campuses. Their roles come from kmu-cms; here you choose where in the academic structure their permissions apply."
        />

        <form
            class="flex max-w-xl gap-2"
            role="search"
            @submit.prevent="submit"
        >
            <Input
                v-model="term"
                type="search"
                maxlength="100"
                placeholder="Name, email or employee ID"
                aria-label="Search staff"
            />
            <Button type="submit" variant="outline">Search</Button>
        </form>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium">Employee ID</th>
                        <th class="px-3 py-2 font-medium">Campus</th>
                        <th class="px-3 py-2 font-medium">KMU Assessment</th>
                        <th class="px-3 py-2 font-medium">Scopes</th>
                        <th class="px-3 py-2">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="person in staff.data"
                        :key="person.staffId"
                        class="border-t"
                    >
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ person.name }}</div>
                            <div class="text-muted-foreground text-xs">
                                {{ person.email }}
                            </div>
                        </td>
                        <td class="px-3 py-2">{{ person.employeeId }}</td>
                        <td class="px-3 py-2">{{ person.branch ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <Badge v-if="!person.isActive" variant="destructive"
                                >Deactivated</Badge
                            >
                            <Badge
                                v-else-if="person.signedIn"
                                variant="secondary"
                                >Signed in before</Badge
                            >
                            <Badge v-else variant="outline">Not yet</Badge>
                        </td>
                        <td class="px-3 py-2">{{ person.scopeCount }}</td>
                        <td class="px-3 py-2 text-right">
                            <Button as-child size="sm" variant="outline">
                                <Link :href="show(person.staffId)">Manage</Link>
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="staff.data.length === 0">
                        <td
                            colspan="6"
                            class="text-muted-foreground px-3 py-8 text-center"
                        >
                            No staff found in your campuses.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :page="staff" />
    </div>
</template>
