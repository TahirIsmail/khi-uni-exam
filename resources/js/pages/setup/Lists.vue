<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Pencil } from '@lucide/vue';
import { ref } from 'vue';
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
            {
                title: 'Intakes, exam types & disciplines',
                href: setup.lists.index(),
            },
        ],
    },
});

type Intake = {
    id: number;
    name: string;
    startDate: string | null;
    endDate: string | null;
};
type ExamType = {
    id: number;
    code: string;
    name: string;
    calendar: 'annual' | 'semester' | 'any';
    isResit: boolean;
    isActive: boolean;
};
type Discipline = { id: number; code: string; name: string; isActive: boolean };

defineProps<{
    intakes: Intake[];
    examTypes: ExamType[];
    disciplines: Discipline[];
}>();

const select =
    'border-input bg-background h-9 w-full min-w-0 rounded-md border px-2 text-sm';
const keep = { preserveScroll: true };

// ---- intakes ----
const intakeForm = useForm({ name: '', start_date: '', end_date: '' });
const editingIntake = ref<number | null>(null);
function editIntake(row: Intake): void {
    editingIntake.value = row.id;
    intakeForm.clearErrors();
    intakeForm.name = row.name;
    intakeForm.start_date = row.startDate ?? '';
    intakeForm.end_date = row.endDate ?? '';
}
function saveIntake(): void {
    const done = {
        ...keep,
        onSuccess: () => {
            intakeForm.reset();
            editingIntake.value = null;
        },
    };
    if (editingIntake.value === null) {
        intakeForm.post(setup.intakes.store().url, done);
    } else {
        intakeForm.put(setup.intakes.update(editingIntake.value).url, done);
    }
}

// ---- exam types ----
const typeForm = useForm({
    code: '',
    name: '',
    calendar: 'any',
    is_resit: false,
    is_active: true,
});
const editingType = ref<number | null>(null);
function editType(row: ExamType): void {
    editingType.value = row.id;
    typeForm.clearErrors();
    typeForm.code = row.code;
    typeForm.name = row.name;
    typeForm.calendar = row.calendar;
    typeForm.is_resit = row.isResit;
    typeForm.is_active = row.isActive;
}
function saveType(): void {
    const done = {
        ...keep,
        onSuccess: () => {
            typeForm.reset();
            editingType.value = null;
        },
    };
    if (editingType.value === null) {
        typeForm.post(setup.examTypes.store().url, done);
    } else {
        typeForm.put(setup.examTypes.update(editingType.value).url, done);
    }
}

// ---- disciplines ----
const disciplineForm = useForm({ code: '', name: '', is_active: true });
const editingDiscipline = ref<number | null>(null);
function editDiscipline(row: Discipline): void {
    editingDiscipline.value = row.id;
    disciplineForm.clearErrors();
    disciplineForm.code = row.code;
    disciplineForm.name = row.name;
    disciplineForm.is_active = row.isActive;
}
function saveDiscipline(): void {
    const done = {
        ...keep,
        onSuccess: () => {
            disciplineForm.reset();
            editingDiscipline.value = null;
        },
    };
    if (editingDiscipline.value === null) {
        disciplineForm.post(setup.disciplines.store().url, done);
    } else {
        disciplineForm.put(
            setup.disciplines.update(editingDiscipline.value).url,
            done,
        );
    }
}
</script>

<template>
    <Head title="Intakes, exam types & disciplines" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Intakes, exam types & disciplines"
            description="The short lists an exam or a question chooses from."
        />

        <section class="rounded-xl border shadow-xs">
            <header class="border-b px-4 py-3">
                <h3 class="font-medium">Intakes (sessions)</h3>
                <p class="text-muted-foreground text-sm">
                    The batch an exam is for, e.g. “Fall 2026”. Optional on an
                    exam.
                </p>
            </header>
            <div class="grid gap-3 p-4">
                <form
                    class="grid gap-2 sm:grid-cols-[1fr_10rem_10rem_auto]"
                    @submit.prevent="saveIntake"
                >
                    <div>
                        <Input
                            v-model="intakeForm.name"
                            placeholder="Name"
                            maxlength="60"
                        />
                        <InputError :message="intakeForm.errors.name" />
                    </div>
                    <Input v-model="intakeForm.start_date" type="date" />
                    <div>
                        <Input v-model="intakeForm.end_date" type="date" />
                        <InputError :message="intakeForm.errors.end_date" />
                    </div>
                    <div class="flex gap-1">
                        <Button
                            type="submit"
                            :disabled="intakeForm.processing"
                            >{{
                                editingIntake === null ? 'Add' : 'Save'
                            }}</Button
                        >
                        <Button
                            v-if="editingIntake !== null"
                            type="button"
                            variant="ghost"
                            @click="
                                editingIntake = null;
                                intakeForm.reset();
                            "
                            >Cancel</Button
                        >
                    </div>
                </form>
                <div
                    v-for="row in intakes"
                    :key="row.id"
                    class="flex items-center justify-between border-t pt-2 text-sm"
                >
                    <span
                        >{{ row.name }}
                        <span class="text-muted-foreground"
                            >{{ row.startDate ?? ''
                            }}{{ row.endDate ? ` – ${row.endDate}` : '' }}</span
                        ></span
                    >
                    <Button size="sm" variant="ghost" @click="editIntake(row)"
                        ><Pencil
                    /></Button>
                </div>
            </div>
        </section>

        <section class="rounded-xl border shadow-xs">
            <header class="border-b px-4 py-3">
                <h3 class="font-medium">Exam types</h3>
                <p class="text-muted-foreground text-sm">
                    “Entry Test” is already there. Calendar: which programmes
                    may use it.
                </p>
            </header>
            <div class="grid gap-3 p-4">
                <form
                    class="grid gap-2 sm:grid-cols-[8rem_1fr_9rem_auto_auto_auto]"
                    @submit.prevent="saveType"
                >
                    <div>
                        <Input
                            v-model="typeForm.code"
                            placeholder="Code"
                            maxlength="30"
                        />
                        <InputError :message="typeForm.errors.code" />
                    </div>
                    <div>
                        <Input
                            v-model="typeForm.name"
                            placeholder="Name"
                            maxlength="60"
                        />
                        <InputError :message="typeForm.errors.name" />
                    </div>
                    <select v-model="typeForm.calendar" :class="select">
                        <option value="any">Any</option>
                        <option value="annual">Annual</option>
                        <option value="semester">Semester</option>
                    </select>
                    <label class="flex items-center gap-2 text-sm"
                        ><Checkbox v-model="typeForm.is_resit" /> Resit</label
                    >
                    <label class="flex items-center gap-2 text-sm"
                        ><Checkbox v-model="typeForm.is_active" /> In use</label
                    >
                    <div class="flex gap-1">
                        <Button type="submit" :disabled="typeForm.processing">{{
                            editingType === null ? 'Add' : 'Save'
                        }}</Button>
                        <Button
                            v-if="editingType !== null"
                            type="button"
                            variant="ghost"
                            @click="
                                editingType = null;
                                typeForm.reset();
                            "
                            >Cancel</Button
                        >
                    </div>
                </form>
                <div
                    v-for="row in examTypes"
                    :key="row.id"
                    class="flex items-center justify-between border-t pt-2 text-sm"
                >
                    <span>
                        {{ row.name }}
                        <span
                            class="text-muted-foreground ml-1 font-mono text-xs"
                            >{{ row.code }}</span
                        >
                        <span
                            class="text-muted-foreground ml-1 text-xs capitalize"
                            >{{ row.calendar }}</span
                        >
                        <Badge v-if="row.isResit" variant="outline" class="ml-1"
                            >Resit</Badge
                        >
                        <Badge
                            v-if="!row.isActive"
                            variant="secondary"
                            class="ml-1"
                            >Not in use</Badge
                        >
                    </span>
                    <Button size="sm" variant="ghost" @click="editType(row)"
                        ><Pencil
                    /></Button>
                </div>
            </div>
        </section>

        <section class="rounded-xl border shadow-xs">
            <header class="border-b px-4 py-3">
                <h3 class="font-medium">Disciplines</h3>
                <p class="text-muted-foreground text-sm">
                    Optional: subject departments a course's subjects can be
                    linked to, e.g. Biology.
                </p>
            </header>
            <div class="grid gap-3 p-4">
                <form
                    class="grid gap-2 sm:grid-cols-[8rem_1fr_auto_auto]"
                    @submit.prevent="saveDiscipline"
                >
                    <div>
                        <Input
                            v-model="disciplineForm.code"
                            placeholder="Code"
                            maxlength="20"
                        />
                        <InputError :message="disciplineForm.errors.code" />
                    </div>
                    <div>
                        <Input
                            v-model="disciplineForm.name"
                            placeholder="Name"
                            maxlength="150"
                        />
                        <InputError :message="disciplineForm.errors.name" />
                    </div>
                    <label class="flex items-center gap-2 text-sm"
                        ><Checkbox v-model="disciplineForm.is_active" /> In
                        use</label
                    >
                    <div class="flex gap-1">
                        <Button
                            type="submit"
                            :disabled="disciplineForm.processing"
                            >{{
                                editingDiscipline === null ? 'Add' : 'Save'
                            }}</Button
                        >
                        <Button
                            v-if="editingDiscipline !== null"
                            type="button"
                            variant="ghost"
                            @click="
                                editingDiscipline = null;
                                disciplineForm.reset();
                            "
                            >Cancel</Button
                        >
                    </div>
                </form>
                <div
                    v-for="row in disciplines"
                    :key="row.id"
                    class="flex items-center justify-between border-t pt-2 text-sm"
                >
                    <span>
                        {{ row.name }}
                        <span
                            class="text-muted-foreground ml-1 font-mono text-xs"
                            >{{ row.code }}</span
                        >
                        <Badge
                            v-if="!row.isActive"
                            variant="secondary"
                            class="ml-1"
                            >Not in use</Badge
                        >
                    </span>
                    <Button
                        size="sm"
                        variant="ghost"
                        @click="editDiscipline(row)"
                        ><Pencil
                    /></Button>
                </div>
            </div>
        </section>
    </div>
</template>
