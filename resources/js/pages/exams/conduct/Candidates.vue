<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Clock, Copy, Eye, FileText, Upload, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import conduct from '@/routes/conduct';
import type {
    CandidateAbilities,
    CandidateRow,
    CandidateStatus,
    CandidateSummary,
    CentreChoice,
    ExaminationDetail,
    ImportResult,
} from '@/types';

const props = defineProps<{
    examination: ExaminationDetail;
    candidates: CandidateRow[];
    summary: CandidateSummary;
    can: CandidateAbilities;
    centres: CentreChoice[];
    next: {
        sitUrl: string;
        published: boolean;
        canPreview: boolean;
        canMonitor: boolean;
    };
}>();

// ---- what comes after this page: check-in, the sign-in address, watching it -------------------
const copied = ref(false);
async function copySitUrl(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.next.sitUrl);
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 2000);
    } catch {
        window.prompt('Copy the sign-in address:', props.next.sitUrl);
    }
}

defineOptions({
    layout: { breadcrumbs: [{ title: 'Conduct Exam', href: conduct.index() }] },
});

const page = usePage();
const flash = computed(
    () => (page.flash ?? {}) as { importResult?: ImportResult },
);

// ---- import -----------------------------------------------------------------------------------
const importForm = useForm<{ file: File | null }>({ file: null });
function pickFile(event: Event): void {
    importForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function upload(): void {
    importForm.post(conduct.candidates.import(props.examination.id).url, {
        forceFormData: true,
        onSuccess: () => importForm.reset(),
    });
}

// ---- auto allocation ----------------------------------------------------------------------------
const allocateForm = useForm({ centre_id: null as number | null });
function allocateAll(): void {
    if (allocateForm.centre_id === null) {
        return;
    }
    allocateForm.post(conduct.candidates.allocate(props.examination.id).url, {
        preserveScroll: true,
    });
}

// ---- per-candidate seat, by hand -----------------------------------------------------------------
const editingSeat = ref<number | null>(null);
const seatForm = useForm({ room_id: null as number | null, seat_no: '' });
const allRooms = computed(() =>
    props.centres.flatMap((centre) =>
        centre.rooms.map((room) => ({
            id: room.id,
            label: `${centre.name} — ${room.name}`,
        })),
    ),
);
function startSeat(candidate: CandidateRow): void {
    editingSeat.value = candidate.id;
    seatForm.room_id = null;
    seatForm.seat_no = '';
}
function saveSeat(candidate: CandidateRow): void {
    if (seatForm.room_id === null) {
        return;
    }
    seatForm.post(
        conduct.candidates.allocateOne([props.examination.id, candidate.id])
            .url,
        { preserveScroll: true, onSuccess: () => (editingSeat.value = null) },
    );
}

// ---- extra time -----------------------------------------------------------------------------
const editingExtraTime = ref<number | null>(null);
const extraTimeForm = useForm({ minutes: null as number | null, reason: '' });
function startExtraTime(candidate: CandidateRow): void {
    editingExtraTime.value = candidate.id;
    extraTimeForm.minutes = candidate.extraTimeMinutes;
    extraTimeForm.reason = candidate.extraTimeReason ?? '';
}
function saveExtraTime(candidate: CandidateRow): void {
    extraTimeForm.post(
        conduct.candidates.extraTime([props.examination.id, candidate.id]).url,
        {
            preserveScroll: true,
            onSuccess: () => (editingExtraTime.value = null),
        },
    );
}
function clearExtraTime(candidate: CandidateRow): void {
    extraTimeForm.minutes = null;
    extraTimeForm.reason = '';
    saveExtraTime(candidate);
}

const statusStyle: Record<
    CandidateStatus,
    'secondary' | 'outline' | 'default'
> = {
    enrolled: 'secondary',
    allocated: 'outline',
    checked_in: 'default',
};
</script>

<template>
    <Head :title="`Candidates — ${examination.title}`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <Heading
                :title="`Candidates — ${examination.title}`"
                :description="`${examination.reference} · ${examination.course}`"
            />
            <div v-if="next.canPreview" class="flex flex-wrap gap-2">
                <Button as-child variant="outline" data-test="preview-paper">
                    <Link :href="`/exams/${examination.id}/paper/preview`"
                        ><FileText /> Preview paper</Link
                    >
                </Button>
                <Button as-child variant="outline" data-test="preview-exam">
                    <a
                        :href="conduct.preview(examination.id).url"
                        target="_blank"
                        ><Eye /> Preview as candidate</a
                    >
                </Button>
            </div>
        </div>

        <!-- After the roster: check-in on the day, then candidates sign in and sit it. -->
        <div
            class="grid gap-3 rounded-xl border p-4 text-sm shadow-xs"
            data-test="next-steps"
        >
            <h2 class="font-medium">On the day of the examination</h2>
            <p
                v-if="!next.published"
                class="rounded-md bg-amber-50 px-3 py-2 text-amber-900 dark:bg-amber-950 dark:text-amber-100"
                data-test="not-published"
            >
                The paper is not published yet, so candidates cannot sign in.
                Publish it from the examination's paper first.
            </p>
            <ol class="grid list-decimal gap-2 pl-5">
                <li>
                    <Link
                        v-if="can.checkin"
                        :href="conduct.checkin(examination.id)"
                        class="font-medium underline-offset-4 hover:underline"
                        >Check in</Link
                    ><span v-else class="font-medium">Check in</span> each
                    candidate as they arrive. Each one is given an exam PIN.
                </li>
                <li>
                    On the exam computer the candidate opens
                    <code class="bg-muted rounded px-1" data-test="sit-url">{{
                        next.sitUrl
                    }}</code>
                    <Button
                        size="sm"
                        variant="ghost"
                        class="ml-1"
                        data-test="copy-sit-url"
                        @click="copySitUrl"
                        ><Copy /> {{ copied ? 'Copied' : 'Copy' }}</Button
                    >
                    and signs in with their candidate number and PIN.
                </li>
                <li>
                    <Link
                        v-if="next.canMonitor"
                        :href="conduct.monitor(examination.id)"
                        class="font-medium underline-offset-4 hover:underline"
                        >Monitor</Link
                    ><span v-else class="font-medium">Monitor</span> who is
                    sitting it, add time or pause a room while it runs.
                </li>
            </ol>
        </div>

        <div class="flex flex-wrap gap-4 text-sm" data-test="summary">
            <div class="rounded-xl border px-4 py-3">
                <div class="text-muted-foreground text-xs">Total</div>
                <div class="text-lg font-medium tabular-nums">
                    {{ summary.total }}
                </div>
            </div>
            <div class="rounded-xl border px-4 py-3">
                <div class="text-muted-foreground text-xs">
                    Not yet allocated
                </div>
                <div class="text-lg font-medium tabular-nums">
                    {{ summary.enrolled }}
                </div>
            </div>
            <div class="rounded-xl border px-4 py-3">
                <div class="text-muted-foreground text-xs">Allocated</div>
                <div class="text-lg font-medium tabular-nums">
                    {{ summary.allocated }}
                </div>
            </div>
            <div class="rounded-xl border px-4 py-3">
                <div class="text-muted-foreground text-xs">Checked in</div>
                <div class="text-lg font-medium tabular-nums">
                    {{ summary.checkedIn }}
                </div>
            </div>
        </div>

        <form
            v-if="can.manage"
            class="grid gap-3 rounded-xl border p-4 shadow-xs"
            data-test="import-form"
            @submit.prevent="upload"
        >
            <Label for="file">Add candidates from a CSV file</Label>
            <p class="text-muted-foreground text-xs">
                Columns: candidate_no, name, and optionally roll_no, cnic,
                email, phone. A candidate number already on this roster is
                skipped, not overwritten.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <Input
                    id="file"
                    type="file"
                    accept=".csv,.txt"
                    class="max-w-xs"
                    @change="pickFile"
                />
                <Button
                    type="submit"
                    :disabled="importForm.processing || !importForm.file"
                    data-test="upload-candidates"
                >
                    <Upload /> Upload
                </Button>
            </div>
            <p v-if="importForm.errors.file" class="text-destructive text-sm">
                {{ importForm.errors.file }}
            </p>

            <div
                v-if="
                    flash.importResult && flash.importResult.errors.length > 0
                "
                class="grid gap-1"
                data-test="import-errors"
            >
                <p class="text-sm font-medium">
                    {{ flash.importResult.errors.length }} row(s) were skipped:
                </p>
                <ul class="text-muted-foreground grid gap-0.5 text-xs">
                    <li
                        v-for="error in flash.importResult.errors"
                        :key="error.row"
                    >
                        Row {{ error.row }}: {{ error.message }}
                    </li>
                </ul>
            </div>
        </form>

        <form
            v-if="can.allocate && centres.length > 0"
            class="flex flex-wrap items-end gap-2 rounded-xl border p-4 shadow-xs"
            data-test="allocate-form"
            @submit.prevent="allocateAll"
        >
            <div class="grid gap-1.5">
                <Label for="centre">Allocate everyone not yet seated to</Label>
                <select
                    id="centre"
                    v-model="allocateForm.centre_id"
                    class="border-input bg-background h-9 min-w-56 rounded-md border px-2 text-sm"
                    data-test="allocate-centre"
                >
                    <option :value="null">Choose a centre</option>
                    <option
                        v-for="centre in centres"
                        :key="centre.id"
                        :value="centre.id"
                    >
                        {{ centre.name }}
                    </option>
                </select>
            </div>
            <Button
                type="submit"
                :disabled="
                    allocateForm.processing || allocateForm.centre_id === null
                "
                data-test="allocate-all"
            >
                <Users /> Allocate
            </Button>
        </form>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Candidate</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">Seat</th>
                        <th class="px-3 py-2 font-medium">Extra time</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="candidate in candidates"
                        :key="candidate.id"
                        class="hover:bg-muted/40 border-t align-top"
                        :data-candidate="candidate.id"
                    >
                        <td class="px-3 py-2">
                            <div class="font-mono text-xs">
                                {{ candidate.candidateNo }}
                            </div>
                            <div class="font-medium">{{ candidate.name }}</div>
                            <div
                                v-if="candidate.rollNo"
                                class="text-muted-foreground text-xs"
                            >
                                Roll {{ candidate.rollNo }}
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <Badge :variant="statusStyle[candidate.status]">{{
                                candidate.statusLabel
                            }}</Badge>
                        </td>
                        <td class="px-3 py-2">
                            <template v-if="editingSeat === candidate.id">
                                <div class="flex flex-wrap items-center gap-1">
                                    <select
                                        v-model="seatForm.room_id"
                                        class="border-input bg-background h-8 min-w-40 rounded-md border px-2 text-xs"
                                        data-test="seat-room"
                                    >
                                        <option :value="null">Room…</option>
                                        <option
                                            v-for="room in allRooms"
                                            :key="room.id"
                                            :value="room.id"
                                        >
                                            {{ room.label }}
                                        </option>
                                    </select>
                                    <Input
                                        v-model="seatForm.seat_no"
                                        placeholder="Seat"
                                        class="h-8 w-20"
                                        data-test="seat-no"
                                    />
                                    <Button
                                        size="sm"
                                        :disabled="
                                            seatForm.processing ||
                                            seatForm.room_id === null
                                        "
                                        data-test="save-seat"
                                        @click="saveSeat(candidate)"
                                        >Save</Button
                                    >
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        @click="editingSeat = null"
                                        >Cancel</Button
                                    >
                                </div>
                            </template>
                            <template v-else>
                                <span v-if="candidate.room"
                                    >{{ candidate.centre }} — {{ candidate.room
                                    }}<span v-if="candidate.seatNo">
                                        · {{ candidate.seatNo }}</span
                                    ></span
                                >
                                <span v-else class="text-muted-foreground"
                                    >Not seated</span
                                >
                                <Button
                                    v-if="
                                        can.allocate &&
                                        candidate.status !== 'checked_in'
                                    "
                                    size="sm"
                                    variant="ghost"
                                    data-test="edit-seat"
                                    @click="startSeat(candidate)"
                                    >Change</Button
                                >
                            </template>
                        </td>
                        <td class="px-3 py-2">
                            <template v-if="editingExtraTime === candidate.id">
                                <div class="grid gap-1">
                                    <Input
                                        :model-value="
                                            extraTimeForm.minutes ?? ''
                                        "
                                        type="number"
                                        min="1"
                                        max="600"
                                        placeholder="Minutes"
                                        class="h-8 w-24"
                                        data-test="extra-minutes"
                                        @update:model-value="
                                            (value) =>
                                                (extraTimeForm.minutes =
                                                    value === ''
                                                        ? null
                                                        : Number(value))
                                        "
                                    />
                                    <Input
                                        v-model="extraTimeForm.reason"
                                        placeholder="Reason"
                                        maxlength="300"
                                        class="h-8"
                                        data-test="extra-reason"
                                    />
                                    <div class="flex gap-1">
                                        <Button
                                            size="sm"
                                            :disabled="extraTimeForm.processing"
                                            data-test="save-extra-time"
                                            @click="saveExtraTime(candidate)"
                                            >Save</Button
                                        >
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            @click="editingExtraTime = null"
                                            >Cancel</Button
                                        >
                                    </div>
                                </div>
                            </template>
                            <template v-else>
                                <span v-if="candidate.extraTimeMinutes"
                                    >{{ candidate.extraTimeMinutes }} min<Clock
                                        class="ml-1 inline size-3"
                                /></span>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                                <Button
                                    v-if="can.extraTime"
                                    size="sm"
                                    variant="ghost"
                                    data-test="edit-extra-time"
                                    @click="startExtraTime(candidate)"
                                    >Change</Button
                                >
                                <Button
                                    v-if="
                                        can.extraTime &&
                                        candidate.extraTimeMinutes
                                    "
                                    size="sm"
                                    variant="ghost"
                                    data-test="clear-extra-time"
                                    @click="clearExtraTime(candidate)"
                                    >Remove</Button
                                >
                            </template>
                        </td>
                    </tr>
                    <tr v-if="candidates.length === 0">
                        <td colspan="4" class="px-3 py-10 text-center">
                            <p class="font-medium">No candidates yet</p>
                            <p class="text-muted-foreground mt-1 text-sm">
                                Import a CSV file of candidates to start.
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
