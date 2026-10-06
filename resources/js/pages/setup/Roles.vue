<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import setup from '@/routes/setup';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Setup', href: setup.index() },
            { title: 'Roles', href: setup.roles.index() },
        ],
    },
});

type Box = 'view' | 'add' | 'edit' | 'delete';
type Role = {
    id: number;
    name: string;
    isSuperAdmin: boolean;
    staffCount: number;
    grants: Record<number, Box[]>;
};

const props = defineProps<{
    categories: { id: number; name: string; boxes: Box[] }[];
    roles: Role[];
}>();

const boxes: Box[] = ['view', 'add', 'edit', 'delete'];

const newForm = useForm({ name: '' });
function addRole(): void {
    newForm.post(setup.roles.store().url, {
        preserveScroll: true,
        onSuccess: () => newForm.reset(),
    });
}

// The role being changed: its name and ticked boxes, saved together.
const selectedId = ref<number | null>(
    props.roles.find((r) => !r.isSuperAdmin)?.id ?? props.roles[0]?.id ?? null,
);
const selected = computed(
    () => props.roles.find((r) => r.id === selectedId.value) ?? null,
);
const form = useForm({ name: '', grants: {} as Record<number, Box[]> });
watch(
    selected,
    (role) => {
        form.clearErrors();
        form.name = role?.name ?? '';
        form.grants = Object.fromEntries(
            props.categories.map((c) => [
                c.id,
                [...(role?.grants[c.id] ?? [])],
            ]),
        );
    },
    { immediate: true },
);

function ticked(categoryId: number, box: Box): boolean {
    return form.grants[categoryId]?.includes(box) ?? false;
}
function tick(categoryId: number, box: Box, on: boolean): void {
    const current = form.grants[categoryId] ?? [];
    form.grants = {
        ...form.grants,
        [categoryId]: on
            ? [...new Set([...current, box])]
            : current.filter((b) => b !== box),
    };
}
function tickAll(on: boolean): void {
    form.grants = Object.fromEntries(
        props.categories.map((c) => [c.id, on ? [...c.boxes] : []]),
    );
}
function save(): void {
    if (selected.value === null) {
        return;
    }
    form.put(setup.roles.update(selected.value.id).url, {
        preserveScroll: true,
    });
}
function remove(role: Role): void {
    if (!confirm(`Delete the role "${role.name}"?`)) {
        return;
    }
    router.delete(setup.roles.destroy(role.id).url, {
        preserveScroll: true,
        onSuccess: () => (selectedId.value = props.roles[0]?.id ?? null),
    });
}
</script>

<template>
    <Head title="Roles" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Roles"
            description="Tick what each role may do in the question bank and exams. Give roles to staff under Staff."
        />

        <div class="grid gap-6 lg:grid-cols-[16rem_1fr]">
            <aside class="grid content-start gap-2">
                <button
                    v-for="role in roles"
                    :key="role.id"
                    type="button"
                    class="hover:bg-accent flex items-center justify-between rounded-lg border px-3 py-2 text-left text-sm"
                    :class="
                        role.id === selectedId ? 'border-primary bg-accent' : ''
                    "
                    :data-role="role.id"
                    @click="selectedId = role.id"
                >
                    <span>{{ role.name }}</span>
                    <span class="text-muted-foreground text-xs">{{
                        role.staffCount
                    }}</span>
                </button>
                <form class="mt-2 grid gap-2" @submit.prevent="addRole">
                    <Input
                        v-model="newForm.name"
                        placeholder="New role, e.g. Paper Setter"
                        maxlength="100"
                    />
                    <InputError :message="newForm.errors.name" />
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="newForm.processing"
                        ><Plus /> Add role</Button
                    >
                </form>
            </aside>

            <form
                v-if="selected"
                class="rounded-xl border shadow-xs"
                @submit.prevent="save"
            >
                <header
                    class="flex flex-wrap items-center gap-3 border-b px-4 py-3"
                >
                    <Input
                        v-model="form.name"
                        class="max-w-xs"
                        maxlength="100"
                    />
                    <Badge v-if="selected.isSuperAdmin"
                        >Super Admin: may do everything, including Setup</Badge
                    >
                    <div class="ml-auto flex gap-2">
                        <template v-if="!selected.isSuperAdmin">
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                @click="tickAll(true)"
                                >Tick all</Button
                            >
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                @click="tickAll(false)"
                                >Clear all</Button
                            >
                            <Button
                                v-if="selected.staffCount === 0"
                                type="button"
                                size="sm"
                                variant="ghost"
                                @click="remove(selected)"
                            >
                                <Trash2 />
                            </Button>
                        </template>
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="form.processing"
                            data-test="save-role"
                            >Save</Button
                        >
                    </div>
                </header>
                <InputError :message="form.errors.name" class="px-4 pt-2" />

                <div v-if="!selected.isSuperAdmin" class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">
                                    Question Bank & Exams
                                </th>
                                <th
                                    v-for="box in boxes"
                                    :key="box"
                                    class="px-2 py-2 text-center font-medium capitalize"
                                >
                                    {{ box }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="category in categories"
                                :key="category.id"
                                class="border-t"
                            >
                                <td class="px-4 py-1.5">{{ category.name }}</td>
                                <td
                                    v-for="box in boxes"
                                    :key="box"
                                    class="px-2 py-1.5 text-center"
                                >
                                    <Checkbox
                                        v-if="category.boxes.includes(box)"
                                        :model-value="ticked(category.id, box)"
                                        @update:model-value="
                                            (on) =>
                                                tick(
                                                    category.id,
                                                    box,
                                                    on === true,
                                                )
                                        "
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>
</template>
