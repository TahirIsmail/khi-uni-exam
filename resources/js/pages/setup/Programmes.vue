<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ListTree, Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
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
            { title: 'Programmes & courses', href: setup.programmes.index() },
        ],
    },
});

type Course = {
    id: number;
    code: string;
    title: string;
    professionalId: number;
    termId: number | null;
    creditHours: number | null;
    status: 'active' | 'inactive' | 'retired';
    topics: number;
};
type Programme = {
    id: number;
    name: string;
    code: string;
    calendar: 'annual' | 'semester';
    structure: 'modular' | 'subject';
    years: number;
    isActive: boolean;
    professionals: {
        id: number;
        name: string;
        terms: { id: number; name: string }[];
    }[];
    courses: Course[];
};

defineProps<{ programmes: Programme[] }>();

const select =
    'border-input bg-background h-9 w-full min-w-0 rounded-md border px-2 text-sm disabled:opacity-50';

const showNew = ref(false);
const programmeForm = useForm({
    name: '',
    code: '',
    calendar: 'annual',
    structure: 'modular',
    years: 1,
});
function addProgramme(): void {
    programmeForm.post(setup.programmes.store().url, {
        preserveScroll: true,
        onSuccess: () => {
            programmeForm.reset();
            showNew.value = false;
        },
    });
}

const editingProgramme = ref<number | null>(null);
const editProgrammeForm = useForm({ name: '', code: '', is_active: true });
function startEditProgramme(p: Programme): void {
    editingProgramme.value = p.id;
    editProgrammeForm.name = p.name;
    editProgrammeForm.code = p.code;
    editProgrammeForm.is_active = p.isActive;
}
function saveProgramme(p: Programme): void {
    editProgrammeForm.put(setup.programmes.update(p.id).url, {
        preserveScroll: true,
        onSuccess: () => (editingProgramme.value = null),
    });
}

// Adding a course to one programme, or changing one course.
const courseFor = ref<number | null>(null);
const editingCourse = ref<number | null>(null);
const courseForm = useForm({
    code: '',
    title: '',
    professional_id: null as number | null,
    term_id: null as number | null,
    credit_hours: undefined as number | undefined,
    status: 'active',
});
function startNewCourse(p: Programme): void {
    editingCourse.value = null;
    courseFor.value = p.id;
    courseForm.reset();
    courseForm.clearErrors();
    courseForm.professional_id = p.professionals[0]?.id ?? null;
    courseForm.term_id = p.professionals[0]?.terms[0]?.id ?? null;
}
function startEditCourse(p: Programme, c: Course): void {
    courseFor.value = p.id;
    editingCourse.value = c.id;
    courseForm.clearErrors();
    courseForm.code = c.code;
    courseForm.title = c.title;
    courseForm.professional_id = c.professionalId;
    courseForm.term_id = c.termId;
    courseForm.credit_hours = c.creditHours ?? undefined;
    courseForm.status = c.status;
}
function termsOf(p: Programme): { id: number; name: string }[] {
    return (
        p.professionals.find((y) => y.id === courseForm.professional_id)
            ?.terms ?? []
    );
}
function saveCourse(p: Programme): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            courseFor.value = null;
            editingCourse.value = null;
        },
    };
    courseForm.transform((data) => ({
        ...data,
        term_id: p.calendar === 'semester' ? data.term_id : null,
    }));
    if (editingCourse.value === null) {
        courseForm.post(setup.courses.store(p.id).url, options);
    } else {
        courseForm.put(setup.courses.update(editingCourse.value).url, options);
    }
}
function yearName(p: Programme, c: Course): string {
    const year = p.professionals.find((y) => y.id === c.professionalId);
    const term = year?.terms.find((t) => t.id === c.termId);

    return [year?.name, term?.name].filter(Boolean).join(' · ');
}
</script>

<template>
    <Head title="Programmes & courses" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Programmes & courses"
                description="Each exam is for one course; questions are filed under a course's subjects and topics."
            />
            <Button
                variant="outline"
                data-test="new-programme"
                @click="showNew = !showNew"
            >
                <Plus /> New programme
            </Button>
        </div>

        <form
            v-if="showNew"
            class="grid gap-3 rounded-xl border p-4 shadow-xs sm:grid-cols-2"
            data-test="programme-form"
            @submit.prevent="addProgramme"
        >
            <div class="grid gap-1.5">
                <Label for="p-name">Name *</Label>
                <Input
                    id="p-name"
                    v-model="programmeForm.name"
                    placeholder="e.g. Admissions Entry Test"
                    maxlength="60"
                />
                <InputError :message="programmeForm.errors.name" />
            </div>
            <div class="grid gap-1.5">
                <Label for="p-code">Short code *</Label>
                <Input
                    id="p-code"
                    v-model="programmeForm.code"
                    placeholder="e.g. ENTRY"
                    maxlength="20"
                />
                <InputError :message="programmeForm.errors.code" />
            </div>
            <div class="grid gap-1.5">
                <Label for="p-structure">What sits under a course</Label>
                <select
                    id="p-structure"
                    v-model="programmeForm.structure"
                    :class="select"
                >
                    <option value="modular">
                        Subjects → topics → subtopics (one exam covers several
                        subjects)
                    </option>
                    <option value="subject">
                        Topics → subtopics (one subject per course)
                    </option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="p-calendar">Calendar</Label>
                <select
                    id="p-calendar"
                    v-model="programmeForm.calendar"
                    :class="select"
                >
                    <option value="annual">Annual (by year)</option>
                    <option value="semester">Semester (two per year)</option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="p-years">Years</Label>
                <Input
                    id="p-years"
                    v-model.number="programmeForm.years"
                    type="number"
                    min="1"
                    max="10"
                />
                <InputError :message="programmeForm.errors.years" />
            </div>
            <Button
                type="submit"
                class="self-end justify-self-start"
                :disabled="programmeForm.processing"
                data-test="save-programme"
            >
                Add programme
            </Button>
        </form>

        <p
            v-if="programmes.length === 0 && !showNew"
            class="text-muted-foreground text-sm"
        >
            No programmes yet. For an entry test, add one programme (e.g.
            “Admissions Entry Test”, 1 year), then a course in it, then its
            subjects and topics.
        </p>

        <section
            v-for="p in programmes"
            :key="p.id"
            class="rounded-xl border shadow-xs"
            :data-programme="p.id"
        >
            <header
                class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
            >
                <form
                    v-if="editingProgramme === p.id"
                    class="grid flex-1 gap-2 sm:grid-cols-[1fr_10rem_auto_auto]"
                    @submit.prevent="saveProgramme(p)"
                >
                    <div>
                        <Input
                            v-model="editProgrammeForm.name"
                            maxlength="60"
                        />
                        <InputError :message="editProgrammeForm.errors.name" />
                    </div>
                    <div>
                        <Input
                            v-model="editProgrammeForm.code"
                            maxlength="20"
                        />
                        <InputError :message="editProgrammeForm.errors.code" />
                    </div>
                    <Label class="flex items-center gap-2 font-normal">
                        <Checkbox v-model="editProgrammeForm.is_active" /> In
                        use
                    </Label>
                    <div class="flex gap-1">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="editProgrammeForm.processing"
                            >Save</Button
                        >
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="editingProgramme = null"
                            >Cancel</Button
                        >
                    </div>
                </form>
                <template v-else>
                    <div>
                        <span class="font-medium">{{ p.name }}</span>
                        <span
                            class="text-muted-foreground ml-2 font-mono text-xs"
                            >{{ p.code }}</span
                        >
                        <Badge
                            v-if="!p.isActive"
                            variant="secondary"
                            class="ml-2"
                            >Switched off</Badge
                        >
                        <div class="text-muted-foreground text-xs">
                            {{ p.years }}
                            {{ p.years === 1 ? 'year' : 'years' }} ·
                            {{
                                p.calendar === 'semester'
                                    ? 'semesters'
                                    : 'annual'
                            }}
                            ·
                            {{
                                p.structure === 'modular'
                                    ? 'subjects → topics → subtopics'
                                    : 'topics → subtopics'
                            }}
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            data-test="new-course"
                            @click="startNewCourse(p)"
                            ><Plus /> Course</Button
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="startEditProgramme(p)"
                            ><Pencil
                        /></Button>
                    </div>
                </template>
            </header>

            <div class="grid gap-3 p-4">
                <form
                    v-if="courseFor === p.id"
                    class="grid gap-3 rounded-lg border p-3 sm:grid-cols-2"
                    data-test="course-form"
                    @submit.prevent="saveCourse(p)"
                >
                    <div class="grid gap-1.5">
                        <Label>Course ID *</Label>
                        <Input
                            v-model="courseForm.code"
                            placeholder="e.g. ENT-2026"
                            maxlength="20"
                        />
                        <InputError :message="courseForm.errors.code" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Title *</Label>
                        <Input
                            v-model="courseForm.title"
                            placeholder="e.g. Entry Test 2026"
                            maxlength="200"
                        />
                        <InputError :message="courseForm.errors.title" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Year *</Label>
                        <select
                            v-model.number="courseForm.professional_id"
                            :class="select"
                            @change="
                                courseForm.term_id = termsOf(p)[0]?.id ?? null
                            "
                        >
                            <option
                                v-for="y in p.professionals"
                                :key="y.id"
                                :value="y.id"
                            >
                                {{ y.name }}
                            </option>
                        </select>
                        <InputError
                            :message="courseForm.errors.professional_id"
                        />
                    </div>
                    <div v-if="p.calendar === 'semester'" class="grid gap-1.5">
                        <Label>Semester *</Label>
                        <select
                            v-model.number="courseForm.term_id"
                            :class="select"
                        >
                            <option
                                v-for="t in termsOf(p)"
                                :key="t.id"
                                :value="t.id"
                            >
                                {{ t.name }}
                            </option>
                        </select>
                        <InputError :message="courseForm.errors.term_id" />
                    </div>
                    <div v-if="p.calendar === 'semester'" class="grid gap-1.5">
                        <Label>Credit hours</Label>
                        <Input
                            v-model.number="courseForm.credit_hours"
                            type="number"
                            step="0.5"
                            min="0"
                            max="99"
                        />
                    </div>
                    <div v-if="editingCourse !== null" class="grid gap-1.5">
                        <Label>Status</Label>
                        <select v-model="courseForm.status" :class="select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="retired">Retired</option>
                        </select>
                    </div>
                    <div class="flex gap-2 sm:col-span-2">
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="courseForm.processing"
                            data-test="save-course"
                        >
                            {{ editingCourse === null ? 'Add course' : 'Save' }}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="courseFor = null"
                            >Cancel</Button
                        >
                    </div>
                </form>

                <table
                    v-if="p.courses.length > 0"
                    class="w-full text-left text-sm"
                >
                    <thead class="text-muted-foreground">
                        <tr>
                            <th class="py-1 font-medium">Course</th>
                            <th class="py-1 font-medium">Year</th>
                            <th class="py-1 font-medium">Topics</th>
                            <th class="py-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="c in p.courses"
                            :key="c.id"
                            class="border-t"
                            :data-course="c.id"
                        >
                            <td class="py-1.5">
                                <span class="font-mono text-xs">{{
                                    c.code
                                }}</span>
                                {{ c.title }}
                                <Badge
                                    v-if="c.status !== 'active'"
                                    variant="secondary"
                                    class="ml-1 capitalize"
                                    >{{ c.status }}</Badge
                                >
                            </td>
                            <td class="text-muted-foreground py-1.5">
                                {{ yearName(p, c) }}
                            </td>
                            <td class="py-1.5">{{ c.topics }}</td>
                            <td class="py-1.5 text-right whitespace-nowrap">
                                <Button size="sm" variant="outline" as-child>
                                    <Link
                                        :href="setup.curriculum.show(c.id)"
                                        data-test="curriculum"
                                    >
                                        <ListTree /> Subjects & topics
                                    </Link>
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    @click="startEditCourse(p, c)"
                                    ><Pencil
                                /></Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p
                    v-else-if="courseFor !== p.id"
                    class="text-muted-foreground text-sm"
                >
                    No courses yet.
                </p>
            </div>
        </section>
    </div>
</template>
