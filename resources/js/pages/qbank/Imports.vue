<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Download, FileSpreadsheet, HelpCircle, Upload } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as importsIndex, store, template } from '@/routes/imports';
import { index as questionsIndex } from '@/routes/questions';
import type {
    CourseOption,
    CurriculumNode,
    ImportSummary,
    Paginated,
    QuestionTypeInfo,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Question bank', href: questionsIndex() },
            { title: 'Import from a spreadsheet', href: importsIndex() },
        ],
    },
});

const props = defineProps<{
    imports: Paginated<ImportSummary>;
    canCommit: boolean;
    columns: string[];
    programmes: { id: number; name: string; code: string }[];
    courses: CourseOption[];
    types: QuestionTypeInfo[];
}>();

const form = useForm<{
    file: File | null;
    course_id: number | null;
    node_id: number | null;
    type_id: number | null;
}>({
    file: null,
    course_id: null,
    node_id: null,
    type_id: null,
});

const programmeId = ref<number | null>(null);
const topics = ref<CurriculumNode[]>([]);
const showColumns = ref(false);

const coursesOfProgramme = computed(() =>
    programmeId.value === null
        ? props.courses
        : props.courses.filter(
              (course) => course.programme_id === programmeId.value,
          ),
);

// Topics belong to a course, so they are only fetched once one is chosen.
watch(
    () => form.course_id,
    async (courseId) => {
        topics.value = [];
        form.node_id = null;
        if (courseId === null) {
            return;
        }
        const response = await fetch(
            `/questions/curriculum?course_id=${courseId}`,
            { headers: { Accept: 'application/json' } },
        );
        if (response.ok) {
            topics.value = (
                (await response.json()) as { nodes: CurriculumNode[] }
            ).nodes.filter((node) => node.allows_questions);
        }
    },
);

function pick(event: Event): void {
    form.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submit(): void {
    form.post(store.url(), { forceFormData: true });
}

const statusStyles: Record<string, string> = {
    checked: 'secondary',
    committed: 'default',
    discarded: 'outline',
    failed: 'destructive',
};
</script>

<template>
    <Head title="Import questions" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                title="Import from a spreadsheet"
                description="Upload a CSV or Excel file of questions. Every row is checked first and shown to you; nothing reaches the question bank until you commit it."
            />
            <Button as-child variant="outline">
                <a :href="template.url()" data-test="download-template"
                    ><Download /> Download the template</a
                >
            </Button>
        </div>

        <form
            class="grid gap-4 rounded-xl border p-4 shadow-xs"
            data-test="upload-form"
            @submit.prevent="submit"
        >
            <div class="grid gap-1.5">
                <Label for="file">The file</Label>
                <Input
                    id="file"
                    type="file"
                    accept=".csv,.tsv,.txt,.xlsx,.xls"
                    data-test="import-file"
                    @change="pick"
                />
                <p class="text-muted-foreground text-xs">
                    CSV, TSV or Excel (.xlsx), up to 10 MB and 2,000 rows. The
                    first row must name the columns.
                </p>
                <p
                    v-if="form.errors.file"
                    class="text-destructive text-sm"
                    data-test="file-error"
                >
                    {{ form.errors.file }}
                </p>
            </div>

            <div class="grid gap-3 border-t pt-4 sm:grid-cols-2 lg:grid-cols-4">
                <p
                    class="text-muted-foreground text-xs sm:col-span-2 lg:col-span-4"
                >
                    If the whole file is for one course, topic or type, choose
                    them here and you can leave those columns out. A row that
                    names its own course or topic always wins.
                </p>
                <div class="grid gap-1.5">
                    <Label for="programme">Programme</Label>
                    <select
                        id="programme"
                        v-model="programmeId"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        @change="form.course_id = null"
                    >
                        <option :value="null">Any</option>
                        <option
                            v-for="row in programmes"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="course">Course ID</Label>
                    <select
                        id="course"
                        v-model="form.course_id"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        data-test="default-course"
                    >
                        <option :value="null">Named in the file</option>
                        <option
                            v-for="course in coursesOfProgramme"
                            :key="course.id"
                            :value="course.id"
                        >
                            {{ course.code }} — {{ course.title }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="topic">Topic</Label>
                    <select
                        id="topic"
                        v-model="form.node_id"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm disabled:opacity-50"
                        :disabled="topics.length === 0"
                        data-test="default-topic"
                    >
                        <option :value="null">Named in the file</option>
                        <option
                            v-for="node in topics"
                            :key="node.id"
                            :value="node.id"
                        >
                            {{ '— '.repeat(Math.max(0, node.depth - 1))
                            }}{{ node.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="type">Type of question</Label>
                    <select
                        id="type"
                        v-model="form.type_id"
                        class="border-input bg-background h-9 rounded-md border px-2 text-sm"
                        data-test="default-type"
                    >
                        <option :value="null">Named in the file</option>
                        <option
                            v-for="row in types"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.name }}
                        </option>
                    </select>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    type="submit"
                    :disabled="form.file === null || form.processing"
                    data-test="check-file"
                >
                    <Upload />
                    {{ form.processing ? 'Checking…' : 'Check the file' }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    @click="showColumns = !showColumns"
                >
                    <HelpCircle /> Which columns can I use?
                </Button>
            </div>

            <div
                v-if="showColumns"
                class="grid gap-2 border-t pt-4 text-sm"
                data-test="column-help"
            >
                <p class="text-muted-foreground">
                    Column names are matched loosely, so “Question”, “Stem” and
                    “Question text” all mean the same thing. Only the question
                    text is required; the rest depend on the type.
                </p>
                <ul class="flex flex-wrap gap-1.5">
                    <li v-for="column in columns" :key="column">
                        <Badge variant="outline" class="font-mono">{{
                            column
                        }}</Badge>
                    </li>
                </ul>
                <dl class="text-muted-foreground grid gap-1 sm:grid-cols-2">
                    <div>
                        <dt class="text-foreground font-medium">options</dt>
                        <dd>
                            Separated by <code>|</code>, e.g.
                            <code>ECG | Chest radiograph | Echocardiogram</code>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-foreground font-medium">correct</dt>
                        <dd>
                            The letters of the right options, e.g.
                            <code>A</code> or <code>A,C</code>; for a true/false
                            question, <code>true</code>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-foreground font-medium">items</dt>
                        <dd>
                            <code>statement = true</code> for multiple
                            true/false, <code>prompt -&gt; B</code> for matching
                        </dd>
                    </div>
                    <div>
                        <dt class="text-foreground font-medium">answers</dt>
                        <dd>
                            Accepted wordings separated by <code>|</code>, or a
                            number with a tolerance:
                            <code>7.40 ± 0.05</code>
                        </dd>
                    </div>
                </dl>
            </div>
        </form>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">File</th>
                        <th class="px-3 py-2 font-medium">Uploaded</th>
                        <th class="px-3 py-2 font-medium">By</th>
                        <th class="px-3 py-2 font-medium">Rows</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2">
                            <span class="sr-only">Open</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in imports.data"
                        :key="row.id"
                        class="hover:bg-muted/40 border-t align-top"
                        :data-import="row.id"
                    >
                        <td class="px-3 py-2">
                            <Link
                                :href="`/questions/imports/${row.id}`"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ row.name }}
                            </Link>
                            <div
                                class="text-muted-foreground text-xs uppercase"
                            >
                                {{ row.format }}
                            </div>
                        </td>
                        <td
                            class="text-muted-foreground px-3 py-2 whitespace-nowrap"
                        >
                            {{
                                row.uploadedAt
                                    ? new Date(row.uploadedAt).toLocaleString()
                                    : '—'
                            }}
                        </td>
                        <td class="px-3 py-2">{{ row.uploadedBy ?? '—' }}</td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ row.rowsTotal }} read
                            <span
                                v-if="row.rowsInvalid > 0"
                                class="text-destructive"
                                >· {{ row.rowsInvalid }} with a problem</span
                            >
                            <span v-if="row.rowsCommitted > 0"
                                >· {{ row.rowsCommitted }} imported</span
                            >
                        </td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="
                                    (statusStyles[row.status] as 'default') ??
                                    'outline'
                                "
                                >{{ row.status }}</Badge
                            >
                        </td>
                        <td class="px-3 py-2 text-right">
                            <Button as-child size="sm" variant="outline">
                                <Link :href="`/questions/imports/${row.id}`"
                                    >Open</Link
                                >
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="imports.data.length === 0">
                        <td colspan="6" class="px-3 py-10 text-center">
                            <FileSpreadsheet
                                class="text-muted-foreground mx-auto mb-2 size-6"
                            />
                            <p class="font-medium">Nothing imported yet</p>
                            <p class="text-muted-foreground mt-1 text-sm">
                                Download the template, fill in your questions
                                and upload it here.
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="imports.last_page > 1"
            class="flex flex-wrap items-center gap-2 text-sm"
        >
            <span class="text-muted-foreground"
                >{{ imports.from }}–{{ imports.to }} of
                {{ imports.total }}</span
            >
            <template v-for="(link, i) in imports.links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md border px-3 py-1"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-accent'
                    "
                    >{{
                        link.label
                            .replace('&laquo;', '«')
                            .replace('&raquo;', '»')
                    }}</Link
                >
            </template>
        </nav>
    </div>
</template>
