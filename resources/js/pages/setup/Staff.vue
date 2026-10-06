<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import setup from '@/routes/setup';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Setup', href: setup.index() },
            { title: 'Staff', href: setup.staff.index() },
        ],
    },
});

type StaffRow = {
    id: number;
    name: string;
    surname: string;
    email: string;
    phone: string;
    branchId: number | null;
    isActive: boolean;
    roleIds: number[];
    courseIds: number[];
    hasPassword: boolean;
    lastLogin: string | null;
};

const props = defineProps<{
    staff: StaffRow[];
    roles: { id: number; name: string; isSuperAdmin: boolean }[];
    branches: { id: number; name: string }[];
    courses: { id: number; label: string }[];
}>();

const select =
    'border-input bg-background h-9 w-full min-w-0 rounded-md border px-2 text-sm disabled:opacity-50';

const search = ref('');
const shown = computed(() => {
    const q = search.value.trim().toLowerCase();

    return q === ''
        ? props.staff
        : props.staff.filter((s) =>
              `${s.name} ${s.surname} ${s.email}`.toLowerCase().includes(q),
          );
});

const roleName = (id: number): string =>
    props.roles.find((r) => r.id === id)?.name ?? '?';

// One form for adding and for changing: `editing` is null while adding.
const editing = ref<number | null>(null);
const open = ref(false);
const form = useForm({
    name: '',
    surname: '',
    email: '',
    phone: '',
    branch_id: props.branches[0]?.id ?? null,
    is_active: true,
    role_ids: [] as number[],
    course_ids: [] as number[],
    password: '',
});

function startNew(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    open.value = true;
}
function startEdit(row: StaffRow): void {
    editing.value = row.id;
    form.clearErrors();
    form.name = row.name;
    form.surname = row.surname;
    form.email = row.email;
    form.phone = row.phone;
    form.branch_id = row.branchId ?? props.branches[0]?.id ?? null;
    form.is_active = row.isActive;
    form.role_ids = [...row.roleIds];
    form.course_ids = [...row.courseIds];
    form.password = '';
    open.value = true;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function toggle(list: number[], id: number, on: boolean): number[] {
    return on ? [...new Set([...list, id])] : list.filter((x) => x !== id);
}
function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    };
    if (editing.value === null) {
        form.post(setup.staff.store().url, options);
    } else {
        form.put(setup.staff.update(editing.value).url, options);
    }
}
</script>

<template>
    <Head title="Staff" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Staff"
                description="Everyone who signs in to set questions, build exams, run them or mark them."
            />
            <Button variant="outline" data-test="new-staff" @click="startNew">
                <Plus /> New staff member
            </Button>
        </div>

        <form
            v-if="open"
            class="grid gap-4 rounded-xl border p-4 shadow-xs"
            data-test="staff-form"
            @submit.prevent="save"
        >
            <h3 class="font-medium">
                {{
                    editing === null
                        ? 'New staff member'
                        : 'Change staff member'
                }}
            </h3>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="s-name">First name *</Label>
                    <Input id="s-name" v-model="form.name" maxlength="200" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="s-surname">Surname</Label>
                    <Input
                        id="s-surname"
                        v-model="form.surname"
                        maxlength="200"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="s-email">Email (signs in with it) *</Label>
                    <Input
                        id="s-email"
                        v-model="form.email"
                        type="email"
                        maxlength="200"
                    />
                    <InputError :message="form.errors.email" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="s-phone">Phone</Label>
                    <Input id="s-phone" v-model="form.phone" maxlength="50" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="s-branch">Campus *</Label>
                    <select
                        id="s-branch"
                        v-model.number="form.branch_id"
                        :class="select"
                    >
                        <option v-for="b in branches" :key="b.id" :value="b.id">
                            {{ b.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.branch_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="s-password">
                        {{
                            editing === null
                                ? 'Password *'
                                : 'New password (leave empty to keep it)'
                        }}
                    </Label>
                    <Input
                        id="s-password"
                        v-model="form.password"
                        type="text"
                        autocomplete="new-password"
                        minlength="8"
                        maxlength="200"
                    />
                    <InputError :message="form.errors.password" />
                </div>
            </div>

            <div class="grid gap-1.5">
                <Label>Roles *</Label>
                <div class="flex flex-wrap gap-x-5 gap-y-2">
                    <Label
                        v-for="role in roles"
                        :key="role.id"
                        class="flex items-center gap-2 font-normal"
                    >
                        <Checkbox
                            :model-value="form.role_ids.includes(role.id)"
                            @update:model-value="
                                (on) =>
                                    (form.role_ids = toggle(
                                        form.role_ids,
                                        role.id,
                                        on === true,
                                    ))
                            "
                        />
                        {{ role.name }}
                    </Label>
                </div>
                <InputError :message="form.errors.role_ids" />
            </div>

            <details class="rounded-lg border p-3">
                <summary class="cursor-pointer text-sm font-medium">
                    Limit to some courses
                    <span class="text-muted-foreground font-normal">
                        —
                        {{
                            form.course_ids.length === 0
                                ? 'none: works on every course of the campus'
                                : `${form.course_ids.length} chosen`
                        }}
                    </span>
                </summary>
                <div
                    class="mt-3 grid max-h-64 gap-2 overflow-y-auto sm:grid-cols-2"
                >
                    <Label
                        v-for="course in courses"
                        :key="course.id"
                        class="flex items-center gap-2 font-normal"
                    >
                        <Checkbox
                            :model-value="form.course_ids.includes(course.id)"
                            @update:model-value="
                                (on) =>
                                    (form.course_ids = toggle(
                                        form.course_ids,
                                        course.id,
                                        on === true,
                                    ))
                            "
                        />
                        {{ course.label }}
                    </Label>
                    <p
                        v-if="courses.length === 0"
                        class="text-muted-foreground text-sm"
                    >
                        No courses yet.
                    </p>
                </div>
            </details>

            <Label class="flex items-center gap-2 font-normal">
                <Checkbox v-model="form.is_active" /> Can sign in
            </Label>

            <div class="flex gap-2">
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="save-staff"
                    >Save</Button
                >
                <Button type="button" variant="ghost" @click="open = false"
                    >Cancel</Button
                >
            </div>
        </form>

        <div class="rounded-xl border shadow-xs">
            <div class="border-b p-3">
                <Input
                    v-model="search"
                    placeholder="Search name or email"
                    class="max-w-xs"
                />
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Email</th>
                            <th class="px-4 py-2 font-medium">Roles</th>
                            <th class="px-4 py-2 font-medium">Last sign-in</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in shown"
                            :key="row.id"
                            class="border-t"
                            :data-staff="row.id"
                        >
                            <td class="px-4 py-2">
                                {{ row.name }} {{ row.surname }}
                                <Badge
                                    v-if="!row.isActive"
                                    variant="secondary"
                                    class="ml-1"
                                    >Switched off</Badge
                                >
                                <Badge
                                    v-else-if="!row.hasPassword"
                                    variant="outline"
                                    class="ml-1"
                                    >No password</Badge
                                >
                                <div
                                    v-if="row.courseIds.length"
                                    class="text-muted-foreground text-xs"
                                >
                                    Limited to
                                    {{ row.courseIds.length }} course(s)
                                </div>
                            </td>
                            <td class="px-4 py-2">{{ row.email }}</td>
                            <td class="px-4 py-2">
                                {{ row.roleIds.map(roleName).join(', ') }}
                            </td>
                            <td class="text-muted-foreground px-4 py-2">
                                {{ row.lastLogin ?? 'Never' }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    data-test="edit-staff"
                                    @click="startEdit(row)"
                                    ><Pencil
                                /></Button>
                            </td>
                        </tr>
                        <tr v-if="shown.length === 0">
                            <td
                                colspan="5"
                                class="text-muted-foreground px-4 py-6 text-center"
                            >
                                No staff found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
