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
import setup from '@/routes/setup';

type Node = {
    id: number;
    parentId: number | null;
    name: string;
    code: string | null;
    depth: number;
    disciplineId: number | null;
    isActive: boolean;
};
type Level = {
    depth: number;
    code: string;
    name: string;
    takesQuestions: boolean;
};

const props = defineProps<{
    course: { id: number; code: string; title: string; programme: string };
    levels: Level[];
    nodes: Node[];
    disciplines: { id: number; name: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Setup', href: setup.index() },
            { title: 'Programmes & courses', href: setup.programmes.index() },
        ],
    },
});

const select =
    'border-input bg-background h-9 w-full min-w-0 rounded-md border px-2 text-sm';

const levelAt = (depth: number): Level | undefined =>
    props.levels.find((l) => l.depth === depth);

// The tree, in order: each node followed by its children.
const ordered = computed<Node[]>(() => {
    const children = new Map<number | null, Node[]>();
    for (const node of props.nodes) {
        children.set(node.parentId, [
            ...(children.get(node.parentId) ?? []),
            node,
        ]);
    }
    const out: Node[] = [];
    const walk = (parent: number | null): void => {
        for (const node of children.get(parent) ?? []) {
            out.push(node);
            walk(node.id);
        }
    };
    walk(null);

    return out;
});

// Adding: under nothing (top level) or under one node; one name per line.
const addingUnder = ref<number | 'top' | null>(null);
const addForm = useForm({
    parent_id: null as number | null,
    names: '',
    discipline_id: null as number | null,
});
function startAdd(parent: Node | null): void {
    addingUnder.value = parent === null ? 'top' : parent.id;
    addForm.reset();
    addForm.clearErrors();
    addForm.parent_id = parent?.id ?? null;
}
function add(): void {
    addForm.post(setup.curriculum.store(props.course.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            addForm.names = '';
            addingUnder.value = null;
        },
    });
}
const addDepth = computed(() =>
    addingUnder.value === 'top'
        ? 1
        : (props.nodes.find((n) => n.id === addingUnder.value)?.depth ?? 0) + 1,
);

const editing = ref<number | null>(null);
const editForm = useForm({
    name: '',
    code: '',
    discipline_id: null as number | null,
    is_active: true,
});
function startEdit(node: Node): void {
    editing.value = node.id;
    editForm.clearErrors();
    editForm.name = node.name;
    editForm.code = node.code ?? '';
    editForm.discipline_id = node.disciplineId;
    editForm.is_active = node.isActive;
}
function saveEdit(node: Node): void {
    editForm.put(setup.curriculum.update(node.id).url, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}
</script>

<template>
    <Head :title="`${course.code} — subjects & topics`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`${course.code} ${course.title}`"
                :description="`${course.programme}. ${levels.map((l) => l.name).join(' → ')}. Questions are filed at the levels marked “takes questions”.`"
            />
            <Button
                variant="outline"
                data-test="add-top"
                @click="startAdd(null)"
            >
                <Plus /> {{ levelAt(1)?.name ?? 'Item' }}
            </Button>
        </div>

        <div class="flex flex-wrap gap-2 text-xs">
            <Badge
                v-for="level in levels"
                :key="level.depth"
                :variant="level.takesQuestions ? 'default' : 'secondary'"
            >
                {{ level.depth }}. {{ level.name
                }}{{ level.takesQuestions ? ' — takes questions' : '' }}
            </Badge>
        </div>

        <form
            v-if="addingUnder !== null"
            class="grid gap-3 rounded-xl border p-4 shadow-xs"
            data-test="add-form"
            @submit.prevent="add"
        >
            <div class="text-sm font-medium">
                New {{ levelAt(addDepth)?.name.toLowerCase() ?? 'item' }}
                <span
                    v-if="addingUnder !== 'top'"
                    class="text-muted-foreground font-normal"
                >
                    under {{ nodes.find((n) => n.id === addingUnder)?.name }}
                </span>
            </div>
            <textarea
                v-model="addForm.names"
                rows="4"
                class="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                placeholder="One per line — paste a whole list to add them all at once"
                data-test="names"
            />
            <InputError :message="addForm.errors.names" />
            <div
                v-if="
                    levelAt(addDepth)?.code === 'discipline' &&
                    disciplines.length > 0
                "
                class="grid max-w-sm gap-1.5"
            >
                <span class="text-sm">Discipline (optional)</span>
                <select v-model.number="addForm.discipline_id" :class="select">
                    <option :value="null">—</option>
                    <option v-for="d in disciplines" :key="d.id" :value="d.id">
                        {{ d.name }}
                    </option>
                </select>
            </div>
            <div class="flex gap-2">
                <Button
                    type="submit"
                    :disabled="addForm.processing"
                    data-test="save-names"
                    >Add</Button
                >
                <Button
                    type="button"
                    variant="ghost"
                    @click="addingUnder = null"
                    >Cancel</Button
                >
            </div>
        </form>

        <div class="rounded-xl border shadow-xs">
            <p
                v-if="ordered.length === 0"
                class="text-muted-foreground p-4 text-sm"
            >
                Nothing yet. Add the first
                {{ levelAt(1)?.name.toLowerCase() ?? 'item' }}.
            </p>
            <div
                v-for="node in ordered"
                :key="node.id"
                class="flex flex-wrap items-center justify-between gap-2 border-t px-4 py-1.5 text-sm first:border-t-0"
                :style="{ paddingLeft: `${node.depth * 1.25}rem` }"
                :data-node="node.id"
            >
                <form
                    v-if="editing === node.id"
                    class="grid flex-1 gap-2 sm:grid-cols-[1fr_8rem_auto_auto]"
                    @submit.prevent="saveEdit(node)"
                >
                    <div>
                        <Input v-model="editForm.name" maxlength="200" />
                        <InputError :message="editForm.errors.name" />
                    </div>
                    <Input
                        v-model="editForm.code"
                        placeholder="Code"
                        maxlength="30"
                    />
                    <label class="flex items-center gap-2">
                        <Checkbox v-model="editForm.is_active" /> In use
                    </label>
                    <div class="flex gap-1">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="editForm.processing"
                            >Save</Button
                        >
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="editing = null"
                            >Cancel</Button
                        >
                    </div>
                </form>
                <template v-else>
                    <div>
                        <span :class="node.depth === 1 ? 'font-medium' : ''">{{
                            node.name
                        }}</span>
                        <span
                            v-if="node.code"
                            class="text-muted-foreground ml-2 font-mono text-xs"
                            >{{ node.code }}</span
                        >
                        <span class="text-muted-foreground ml-2 text-xs">{{
                            levelAt(node.depth)?.name
                        }}</span>
                        <Badge
                            v-if="!node.isActive"
                            variant="secondary"
                            class="ml-2"
                            >Switched off</Badge
                        >
                    </div>
                    <div class="flex gap-1">
                        <Button
                            v-if="levelAt(node.depth + 1)"
                            size="sm"
                            variant="ghost"
                            data-test="add-child"
                            @click="startAdd(node)"
                        >
                            <Plus /> {{ levelAt(node.depth + 1)?.name }}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="startEdit(node)"
                            ><Pencil
                        /></Button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
