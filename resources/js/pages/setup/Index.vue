<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    BookOpen,
    ListChecks,
    Pencil,
    Plus,
    ShieldCheck,
    Users,
} from '@lucide/vue';
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
    layout: { breadcrumbs: [{ title: 'Setup', href: setup.index() }] },
});

type Branch = { id: number; name: string; code: string; isActive: boolean };

const props = defineProps<{
    branches: Branch[];
    settings: {
        mfa: boolean;
        reviewsRequired: number;
        reviewDays: number;
        autoActivate: boolean;
        acceptStores: boolean;
        academicReview: boolean;
        anonymous: boolean;
        deviceApproval: boolean;
    };
    counts: {
        staff: number;
        roles: number;
        programmes: number;
        courses: number;
        intakes: number;
    };
}>();

const sections = [
    {
        title: 'Staff',
        text: 'Who can sign in, their roles, and which courses they work on.',
        href: setup.staff.index(),
        icon: Users,
        count: props.counts.staff,
    },
    {
        title: 'Roles',
        text: 'What each role may do: the Question Bank & Exams checkboxes.',
        href: setup.roles.index(),
        icon: ShieldCheck,
        count: props.counts.roles,
    },
    {
        title: 'Programmes & courses',
        text: 'Programmes, their years, courses, and each course’s subjects and topics.',
        href: setup.programmes.index(),
        icon: BookOpen,
        count: props.counts.programmes,
    },
    {
        title: 'Intakes, exam types & disciplines',
        text: 'The short lists exams and questions choose from.',
        href: setup.lists.index(),
        icon: ListChecks,
        count: props.counts.intakes,
    },
];

const settingsForm = useForm({
    mfa: props.settings.mfa,
    reviews_required: props.settings.reviewsRequired,
    review_days: props.settings.reviewDays,
    auto_activate: props.settings.autoActivate,
    accept_stores: props.settings.acceptStores,
    academic_review: props.settings.academicReview,
    anonymous: props.settings.anonymous,
    device_approval: props.settings.deviceApproval,
});
function saveSettings(): void {
    settingsForm.put(setup.settings.update().url, { preserveScroll: true });
}

const showNewBranch = ref(false);
const branchForm = useForm({ name: '', code: '', is_active: true });
function addBranch(): void {
    branchForm.post(setup.campuses.store().url, {
        preserveScroll: true,
        onSuccess: () => {
            branchForm.reset();
            showNewBranch.value = false;
        },
    });
}
const editingBranch = ref<number | null>(null);
const editBranchForm = useForm({ name: '', code: '', is_active: true });
function startEditBranch(branch: Branch): void {
    editingBranch.value = branch.id;
    editBranchForm.name = branch.name;
    editBranchForm.code = branch.code;
    editBranchForm.is_active = branch.isActive;
}
function saveBranch(branch: Branch): void {
    editBranchForm.put(setup.campuses.update(branch.id).url, {
        preserveScroll: true,
        onSuccess: () => (editingBranch.value = null),
    });
}

const switches = [
    {
        key: 'mfa',
        label: 'Everyone must use an authenticator app (two-factor sign-in)',
    },
    {
        key: 'academic_review',
        label: 'Questions also need the QBank / academic review after the subject review',
    },
    {
        key: 'accept_stores',
        label: 'A question every reviewer accepted is stored in the QBank straight away',
    },
    {
        key: 'auto_activate',
        label: 'An approved question can be used in exams straight away',
    },
    {
        key: 'anonymous',
        label: 'Authors do not see who reviewed their question',
    },
    {
        key: 'device_approval',
        label: 'A computer not seen before waits for the invigilator to approve it (Conduct exam → Centres) before the exam starts on it',
    },
] as const;
</script>

<template>
    <Head title="Setup" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Setup"
            description="Staff, roles, programmes and courses, and how the module works."
        />

        <div class="grid gap-3 sm:grid-cols-2">
            <Link
                v-for="section in sections"
                :key="section.title"
                :href="section.href"
                class="hover:bg-accent flex gap-3 rounded-xl border p-4 shadow-xs"
            >
                <component
                    :is="section.icon"
                    class="text-muted-foreground mt-0.5 size-5 shrink-0"
                />
                <div>
                    <div class="font-medium">
                        {{ section.title }}
                        <span
                            class="text-muted-foreground ml-1 text-sm font-normal"
                            >{{ section.count }}</span
                        >
                    </div>
                    <p class="text-muted-foreground text-sm">
                        {{ section.text }}
                    </p>
                </div>
            </Link>
        </div>

        <section class="rounded-xl border shadow-xs">
            <header
                class="flex items-center justify-between gap-2 border-b px-4 py-3"
            >
                <h3 class="font-medium">Campuses</h3>
                <Button
                    size="sm"
                    variant="outline"
                    @click="showNewBranch = !showNewBranch"
                >
                    <Plus /> New campus
                </Button>
            </header>
            <div class="grid gap-2 p-4">
                <form
                    v-if="showNewBranch"
                    class="grid gap-2 sm:grid-cols-[1fr_10rem_auto]"
                    @submit.prevent="addBranch"
                >
                    <div>
                        <Input
                            v-model="branchForm.name"
                            placeholder="Name"
                            maxlength="255"
                        />
                        <InputError :message="branchForm.errors.name" />
                    </div>
                    <div>
                        <Input
                            v-model="branchForm.code"
                            placeholder="Code"
                            maxlength="50"
                        />
                        <InputError :message="branchForm.errors.code" />
                    </div>
                    <Button type="submit" :disabled="branchForm.processing"
                        >Add</Button
                    >
                </form>
                <div
                    v-for="branch in branches"
                    :key="branch.id"
                    class="flex flex-wrap items-center justify-between gap-2 border-t pt-2 first:border-t-0 first:pt-0"
                >
                    <form
                        v-if="editingBranch === branch.id"
                        class="grid flex-1 gap-2 sm:grid-cols-[1fr_10rem_auto_auto]"
                        @submit.prevent="saveBranch(branch)"
                    >
                        <div>
                            <Input
                                v-model="editBranchForm.name"
                                maxlength="255"
                            />
                            <InputError :message="editBranchForm.errors.name" />
                        </div>
                        <div>
                            <Input
                                v-model="editBranchForm.code"
                                maxlength="50"
                            />
                            <InputError :message="editBranchForm.errors.code" />
                        </div>
                        <Label class="flex items-center gap-2 font-normal">
                            <Checkbox v-model="editBranchForm.is_active" />
                            Active
                        </Label>
                        <div class="flex gap-1">
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="editBranchForm.processing"
                                >Save</Button
                            >
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                @click="editingBranch = null"
                                >Cancel</Button
                            >
                        </div>
                    </form>
                    <template v-else>
                        <div>
                            <span class="font-medium">{{ branch.name }}</span>
                            <span
                                class="text-muted-foreground ml-2 font-mono text-xs"
                                >{{ branch.code }}</span
                            >
                            <Badge
                                v-if="!branch.isActive"
                                variant="secondary"
                                class="ml-2"
                                >Inactive</Badge
                            >
                        </div>
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="startEditBranch(branch)"
                            ><Pencil
                        /></Button>
                    </template>
                </div>
            </div>
        </section>

        <form
            class="rounded-xl border shadow-xs"
            @submit.prevent="saveSettings"
        >
            <header class="border-b px-4 py-3">
                <h3 class="font-medium">Module settings</h3>
            </header>
            <div class="grid gap-3 p-4">
                <Label
                    v-for="item in switches"
                    :key="item.key"
                    class="flex items-start gap-2 font-normal"
                >
                    <Checkbox v-model="settingsForm[item.key]" class="mt-0.5" />
                    {{ item.label }}
                </Label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="reviews"
                            >Reviews needed before a question can be
                            approved</Label
                        >
                        <Input
                            id="reviews"
                            v-model.number="settingsForm.reviews_required"
                            type="number"
                            min="1"
                            max="5"
                        />
                        <InputError
                            :message="settingsForm.errors.reviews_required"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="days">Days a reviewer has</Label>
                        <Input
                            id="days"
                            v-model.number="settingsForm.review_days"
                            type="number"
                            min="1"
                            max="60"
                        />
                        <InputError
                            :message="settingsForm.errors.review_days"
                        />
                    </div>
                </div>
                <Button
                    type="submit"
                    class="justify-self-start"
                    :disabled="settingsForm.processing"
                    >Save settings</Button
                >
            </div>
        </form>
    </div>
</template>
