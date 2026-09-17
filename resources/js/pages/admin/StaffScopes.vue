<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import StaffScopeController from '@/actions/App/Http/Controllers/Admin/StaffScopeController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/admin/staff';
import type { ScopeOption, ScopeType, StaffScope } from '@/types';

const props = defineProps<{
    staff: {
        staffId: number;
        name: string;
        email: string;
        signedIn: boolean;
        branches: string[];
        roles: string[];
        isSuperAdmin: boolean;
        permissionCount: number;
    };
    scopes: StaffScope[];
    options: ScopeOption[];
    canAddAll: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Staff scopes', href: index() }],
    },
});

const typeLabels: Record<ScopeType, string> = {
    all: 'Everywhere in their campuses',
    programme: 'Programme',
    professional: 'Professional',
    course: 'Course',
};

const availableTypes = computed<ScopeType[]>(() => {
    const types: ScopeType[] = props.canAddAll ? ['all'] : [];
    for (const type of ['programme', 'professional', 'course'] as const) {
        if (props.options.some((o) => o.type === type)) {
            types.push(type);
        }
    }
    return types;
});

const form = useForm<{ scope_type: ScopeType | ''; scope_id: number | null }>({
    scope_type: '',
    scope_id: null,
});

const optionsForType = computed(() =>
    props.options.filter((o) => o.type === form.scope_type),
);

function add(): void {
    form.transform((data) =>
        data.scope_type === 'all' ? { scope_type: 'all' } : data,
    ).submit(StaffScopeController.store(props.staff.staffId), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function remove(scope: StaffScope): void {
    if (!window.confirm(`Remove “${scope.label}”?`)) {
        return;
    }
    router.visit(
        StaffScopeController.destroy([props.staff.staffId, scope.id]),
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="`Scopes – ${staff.name}`" />

    <div class="flex max-w-4xl flex-col gap-6 p-4">
        <Heading :title="staff.name" :description="staff.email" />

        <dl class="grid gap-4 rounded-lg border p-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-muted-foreground">Campuses</dt>
                <dd>{{ staff.branches.join(', ') || 'None' }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Roles (from kmu-cms)</dt>
                <dd class="flex flex-wrap gap-1">
                    <Badge
                        v-for="role in staff.roles"
                        :key="role"
                        variant="secondary"
                        >{{ role }}</Badge
                    >
                    <span v-if="staff.roles.length === 0">None</span>
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Permissions</dt>
                <dd>
                    {{
                        staff.isSuperAdmin
                            ? 'All (Super Admin)'
                            : staff.permissionCount
                    }}
                </dd>
            </div>
        </dl>

        <p v-if="staff.isSuperAdmin" class="text-muted-foreground text-sm">
            A Super Admin works everywhere in their campuses; scopes are not
            needed.
        </p>
        <p
            v-else-if="staff.permissionCount === 0"
            class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
            None of this person's roles has permissions in KMU Assessment yet,
            so scopes have no effect until a role is granted permissions.
        </p>

        <section class="flex flex-col gap-3">
            <h3 class="font-medium">Current scopes</h3>
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="scope in scopes"
                    :key="scope.id"
                    class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                >
                    <span>
                        {{ scope.label }}
                        <span
                            v-if="scope.grantedBy"
                            class="text-muted-foreground text-xs"
                        >
                            · added by {{ scope.grantedBy }}</span
                        >
                    </span>
                    <Button
                        size="sm"
                        variant="ghost"
                        class="text-destructive"
                        :data-scope="scope.id"
                        @click="remove(scope)"
                        >Remove</Button
                    >
                </li>
                <li
                    v-if="scopes.length === 0"
                    class="text-muted-foreground px-3 py-4 text-sm"
                >
                    No scopes: this person can open screens but cannot act on
                    any programme, professional or course.
                </li>
            </ul>
        </section>

        <form
            v-if="availableTypes.length > 0"
            class="flex flex-col gap-3 rounded-lg border p-4"
            @submit.prevent="add"
        >
            <h3 class="font-medium">Add a scope</h3>
            <div class="grid gap-3 sm:grid-cols-[12rem_1fr]">
                <div class="flex flex-col gap-1">
                    <Label for="scope_type">Applies to</Label>
                    <select
                        id="scope_type"
                        v-model="form.scope_type"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        required
                        @change="form.scope_id = null"
                    >
                        <option value="" disabled>Choose…</option>
                        <option
                            v-for="type in availableTypes"
                            :key="type"
                            :value="type"
                        >
                            {{ typeLabels[type] }}
                        </option>
                    </select>
                    <InputError :message="form.errors.scope_type" />
                </div>
                <div
                    v-if="form.scope_type && form.scope_type !== 'all'"
                    class="flex flex-col gap-1"
                >
                    <Label for="scope_id">{{
                        typeLabels[form.scope_type]
                    }}</Label>
                    <select
                        id="scope_id"
                        v-model.number="form.scope_id"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        required
                    >
                        <option :value="null" disabled>Choose…</option>
                        <option
                            v-for="option in optionsForType"
                            :key="option.id"
                            :value="option.id"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </div>
            </div>
            <InputError :message="form.errors.scope_id" />
            <InputError
                :message="(form.errors as Record<string, string>).staff"
            />
            <div>
                <Button
                    type="submit"
                    :disabled="form.processing || !form.scope_type"
                    data-test="add-scope"
                    >Add scope</Button
                >
            </div>
        </form>
        <p v-else class="text-muted-foreground text-sm">
            There is nothing in your scopes that you can add for this person.
        </p>
    </div>
</template>
