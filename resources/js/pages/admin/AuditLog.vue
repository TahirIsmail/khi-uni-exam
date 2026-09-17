<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, Download } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { exportMethod, index } from '@/routes/admin/audit';
import type {
    AuditEntry,
    AuditFilters,
    AuditVerification,
    Paginated,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Audit log', href: index() }],
    },
});

const props = defineProps<{
    entries: Paginated<AuditEntry>;
    filters: AuditFilters;
    actions: string[];
    canExport: boolean;
    verification?: AuditVerification;
}>();

const form = reactive({
    action: props.filters.action ?? '',
    actor: props.filters.actor ?? '',
    entity_type: props.filters.entity_type ?? '',
    entity_id: props.filters.entity_id ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const query = computed(() =>
    Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')),
);

const open = ref<number | null>(null);
const verifying = ref(false);

function search(): void {
    router.get(index.url(), query.value, { preserveState: true });
}

function clear(): void {
    router.get(index.url());
}

function verify(): void {
    router.reload({
        only: ['verification'],
        onStart: () => (verifying.value = true),
        onFinish: () => (verifying.value = false),
    });
}

function when(iso: string): string {
    return new Date(iso).toLocaleString();
}

function pretty(value: unknown): string {
    return JSON.stringify(value, null, 2);
}
</script>

<template>
    <Head title="Audit log" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Audit log"
            description="Every change is recorded here and cannot be edited or deleted. You see entries of your campuses; entries that belong to no single campus are shown only to people who work in every campus."
        />

        <div class="flex flex-wrap items-center gap-3">
            <Button
                variant="outline"
                :disabled="verifying"
                data-test="verify-chain"
                @click="verify"
                >{{
                    verifying
                        ? 'Checking…'
                        : 'Check the log has not been altered'
                }}</Button
            >
            <Button v-if="canExport" as-child variant="outline">
                <a :href="exportMethod.url({ query })" data-test="export-audit">
                    <Download /> Export CSV
                </a>
            </Button>
            <p
                v-if="verification"
                class="flex items-center gap-2 text-sm"
                :class="
                    verification.ok
                        ? 'text-green-700 dark:text-green-400'
                        : 'text-destructive'
                "
                role="status"
            >
                <template v-if="verification.ok">
                    <CircleCheck class="size-4" /> Intact: all
                    {{ verification.checked }} entries match their hashes.
                </template>
                <template v-else>
                    <CircleAlert class="size-4" /> Altered at entry #{{
                        verification.first_broken_id
                    }}: {{ verification.problem }}
                </template>
            </p>
        </div>

        <form
            class="grid gap-3 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-6"
            @submit.prevent="search"
        >
            <div class="flex flex-col gap-1 lg:col-span-2">
                <Label for="action">Action</Label>
                <select
                    id="action"
                    v-model="form.action"
                    class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                >
                    <option value="">Any</option>
                    <option
                        v-for="action in actions"
                        :key="action"
                        :value="action"
                    >
                        {{ action }}
                    </option>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <Label for="actor">By (name or email)</Label>
                <Input id="actor" v-model="form.actor" maxlength="100" />
            </div>
            <div class="flex flex-col gap-1">
                <Label for="entity_type">Record type</Label>
                <Input
                    id="entity_type"
                    v-model="form.entity_type"
                    maxlength="60"
                    placeholder="e.g. user"
                />
            </div>
            <div class="flex flex-col gap-1">
                <Label for="from">From</Label>
                <Input id="from" v-model="form.from" type="date" />
            </div>
            <div class="flex flex-col gap-1">
                <Label for="to">To</Label>
                <Input id="to" v-model="form.to" type="date" />
            </div>
            <div class="flex gap-2 lg:col-span-6">
                <Button type="submit">Filter</Button>
                <Button type="button" variant="ghost" @click="clear"
                    >Clear</Button
                >
            </div>
        </form>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">#</th>
                        <th class="px-3 py-2 font-medium">When</th>
                        <th class="px-3 py-2 font-medium">Who</th>
                        <th class="px-3 py-2 font-medium">Action</th>
                        <th class="px-3 py-2 font-medium">Record</th>
                        <th class="px-3 py-2">
                            <span class="sr-only">Details</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="entry in entries.data" :key="entry.id">
                        <tr class="border-t align-top">
                            <td class="text-muted-foreground px-3 py-2">
                                {{ entry.id }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ when(entry.occurredAt) }}
                            </td>
                            <td class="px-3 py-2">
                                {{ entry.actorName ?? entry.actorType }}
                                <div
                                    v-if="entry.actorEmail"
                                    class="text-muted-foreground text-xs"
                                >
                                    {{ entry.actorEmail }}
                                </div>
                            </td>
                            <td class="px-3 py-2">
                                <code>{{ entry.action }}</code>
                            </td>
                            <td class="px-3 py-2">
                                <span v-if="entry.entityType"
                                    >{{ entry.entityType }} #{{
                                        entry.entityId
                                    }}</span
                                >
                            </td>
                            <td class="px-3 py-2 text-right">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    :aria-expanded="open === entry.id"
                                    @click="
                                        open =
                                            open === entry.id ? null : entry.id
                                    "
                                    >{{
                                        open === entry.id ? 'Hide' : 'Details'
                                    }}</Button
                                >
                            </td>
                        </tr>
                        <tr v-if="open === entry.id" class="bg-muted/30">
                            <td colspan="6" class="px-3 py-3">
                                <dl class="grid gap-3 text-xs md:grid-cols-2">
                                    <div
                                        v-if="entry.reason"
                                        class="md:col-span-2"
                                    >
                                        <dt class="text-muted-foreground">
                                            Reason
                                        </dt>
                                        <dd>{{ entry.reason }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">
                                            Before
                                        </dt>
                                        <dd>
                                            <pre
                                                class="overflow-x-auto whitespace-pre-wrap"
                                                >{{
                                                    entry.oldValues === null
                                                        ? '—'
                                                        : pretty(
                                                              entry.oldValues,
                                                          )
                                                }}</pre>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">
                                            After
                                        </dt>
                                        <dd>
                                            <pre
                                                class="overflow-x-auto whitespace-pre-wrap"
                                                >{{
                                                    entry.newValues === null
                                                        ? '—'
                                                        : pretty(
                                                              entry.newValues,
                                                          )
                                                }}</pre>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">
                                            IP address
                                        </dt>
                                        <dd>{{ entry.ip ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted-foreground">
                                            Request
                                        </dt>
                                        <dd>
                                            <code>{{
                                                entry.requestId ?? '—'
                                            }}</code>
                                        </dd>
                                    </div>
                                </dl>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="entries.data.length === 0">
                        <td
                            colspan="6"
                            class="text-muted-foreground px-3 py-8 text-center"
                        >
                            No entries.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :page="entries" />
    </div>
</template>
