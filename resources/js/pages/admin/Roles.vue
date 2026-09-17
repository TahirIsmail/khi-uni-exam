<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import RolePermissionController from '@/actions/App/Http/Controllers/Admin/RolePermissionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/admin/roles';
import type { PermissionGroup, RoleRow } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles & permissions', href: index() }],
    },
});

const props = defineProps<{
    roles: RoleRow[];
    groups: PermissionGroup[];
    canEdit: boolean;
}>();

const selectedId = ref<number | null>(
    props.roles.find((r) => !r.isSuperAdmin)?.id ?? props.roles[0]?.id ?? null,
);
const selected = computed(
    () => props.roles.find((r) => r.id === selectedId.value) ?? null,
);
const readOnly = computed(
    () => !props.canEdit || (selected.value?.isSuperAdmin ?? true),
);

const form = useForm<{ permissions: string[]; reason: string }>({
    permissions: [],
    reason: '',
});

watch(
    selected,
    (role) => {
        form.defaults({
            permissions: [...(role?.permissions ?? [])],
            reason: '',
        });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function toggle(code: string, on: boolean | 'indeterminate'): void {
    const set = new Set(form.permissions);
    if (on === true) {
        set.add(code);
    } else {
        set.delete(code);
    }
    form.permissions = [...set];
}

function save(): void {
    if (!selected.value) {
        return;
    }
    form.submit(RolePermissionController.update(selected.value.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Roles & permissions" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Roles & permissions"
            description="Roles and who holds them are managed in kmu-cms (Settings → Roles). Here you choose what each role may do in KMU Assessment. Changes apply to every campus."
        />

        <p
            v-if="!canEdit"
            class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
            You can view role permissions. Changing them needs access to every
            active campus.
        </p>

        <div class="grid gap-6 lg:grid-cols-[16rem_1fr]">
            <ul class="flex flex-col gap-1" aria-label="Roles">
                <li v-for="role in roles" :key="role.id">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm"
                        :class="
                            role.id === selectedId
                                ? 'border-primary bg-accent'
                                : 'hover:bg-accent'
                        "
                        :aria-current="role.id === selectedId"
                        @click="selectedId = role.id"
                    >
                        <span class="min-w-0 truncate">{{ role.name }}</span>
                        <span class="text-muted-foreground shrink-0 text-xs">
                            {{
                                role.isSuperAdmin
                                    ? 'all'
                                    : role.permissions.length
                            }}
                            · {{ role.staffCount }} staff
                        </span>
                    </button>
                </li>
            </ul>

            <form
                v-if="selected"
                class="flex min-w-0 flex-col gap-6"
                @submit.prevent="save"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-lg font-semibold">{{ selected.name }}</h3>
                    <Badge v-if="selected.isSuperAdmin" variant="secondary">
                        <ShieldCheck /> Super Admin: every permission
                    </Badge>
                </div>

                <InputError :message="form.errors.permissions" />
                <InputError
                    :message="(form.errors as Record<string, string>).role"
                />

                <fieldset
                    v-for="group in groups"
                    :key="group.name"
                    class="rounded-lg border p-4"
                >
                    <legend class="px-1 text-sm font-medium">
                        {{ group.name }}
                    </legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label
                            v-for="permission in group.permissions"
                            :key="permission.code"
                            class="flex items-start gap-3 text-sm"
                            :class="
                                readOnly || !permission.grantable
                                    ? 'cursor-not-allowed'
                                    : 'cursor-pointer'
                            "
                        >
                            <Checkbox
                                class="mt-0.5"
                                :model-value="
                                    form.permissions.includes(permission.code)
                                "
                                :disabled="readOnly || !permission.grantable"
                                :data-permission="permission.code"
                                @update:model-value="
                                    toggle(permission.code, $event)
                                "
                            />
                            <span class="min-w-0">
                                <span class="block">
                                    {{ permission.description }}
                                    <Badge
                                        v-if="permission.privileged"
                                        variant="outline"
                                        class="ml-1"
                                        title="Holders must use two-factor authentication"
                                        >MFA</Badge
                                    >
                                </span>
                                <code class="text-muted-foreground text-xs">{{
                                    permission.code
                                }}</code>
                            </span>
                        </label>
                    </div>
                </fieldset>

                <div v-if="!readOnly" class="flex flex-col gap-2">
                    <Label for="reason">Reason (kept in the audit log)</Label>
                    <Input
                        id="reason"
                        v-model="form.reason"
                        maxlength="500"
                        placeholder="e.g. Approved by the Controller of Examinations"
                    />
                    <InputError :message="form.errors.reason" />
                </div>

                <div v-if="!readOnly" class="flex items-center gap-3">
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.isDirty"
                        data-test="save-role-permissions"
                        >Save</Button
                    >
                    <Button
                        type="button"
                        variant="ghost"
                        :disabled="!form.isDirty"
                        @click="form.reset()"
                        >Undo changes</Button
                    >
                </div>
            </form>
        </div>
    </div>
</template>
