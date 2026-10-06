<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { KeyRound, Printer, Search, UserCheck, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import conduct from '@/routes/conduct';
import type {
    CandidateStatus,
    CheckInRow,
    ExaminationDetail,
    IssuedPin,
} from '@/types';

const props = defineProps<{
    examination: ExaminationDetail;
    search: string;
    results: CheckInRow[];
    pages: { current: number; last: number; total: number };
    counts: { enrolled: number; allocated: number; checkedIn: number };
}>();

// With one exam PIN for everyone, nobody needs a seat or a PIN of their own to be checked in.
const shared = computed(() => props.examination.sharedPin !== null);
const waiting = computed(() =>
    shared.value
        ? props.counts.enrolled + props.counts.allocated
        : props.counts.allocated,
);
const canCheckIn = (candidate: CheckInRow): boolean =>
    candidate.status === 'allocated' ||
    (shared.value && candidate.status === 'enrolled');

const checkingAll = ref(false);
function checkInAll(): void {
    if (
        !confirm(
            `Check in all ${waiting.value} candidates who are not checked in yet?`,
        )
    ) {
        return;
    }
    router.post(
        conduct.checkin.all(props.examination.id).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (checkingAll.value = true),
            onFinish: () => (checkingAll.value = false),
        },
    );
}

function printPins(): void {
    window.print();
}

// Ticked rows, to check in a chosen few at once.
const selected = ref<number[]>([]);
const selectable = computed(() => props.results.filter(canCheckIn));
const allTicked = computed(
    () =>
        selectable.value.length > 0 &&
        selectable.value.every((c) => selected.value.includes(c.id)),
);
function toggleAll(on: boolean): void {
    selected.value = on ? selectable.value.map((c) => c.id) : [];
}
function toggle(id: number, on: boolean): void {
    selected.value = on
        ? [...new Set([...selected.value, id])]
        : selected.value.filter((x) => x !== id);
}
function checkInSelected(): void {
    router.post(
        conduct.checkin.all(props.examination.id).url,
        { candidate_ids: selected.value },
        {
            preserveScroll: true,
            onStart: () => (checkingAll.value = true),
            onFinish: () => {
                checkingAll.value = false;
                selected.value = [];
            },
        },
    );
}

function goTo(pageNo: number): void {
    router.get(
        conduct.checkin(props.examination.id).url,
        {
            ...(props.search === '' ? {} : { search: props.search }),
            page: pageNo,
        },
        { preserveState: true, replace: true },
    );
}

defineOptions({
    layout: { breadcrumbs: [{ title: 'Conduct Exam', href: conduct.index() }] },
});

const page = usePage();
const pin = computed(
    () =>
        (page.flash ?? {}) as {
            pin?: IssuedPin;
            pins?: (IssuedPin & { name: string })[];
        },
);

const query = ref(props.search);
function runSearch(): void {
    router.get(
        conduct.checkin(props.examination.id).url,
        query.value === '' ? {} : { search: query.value },
        { preserveState: true, replace: true },
    );
}

function checkIn(candidate: CheckInRow): void {
    router.post(
        conduct.checkin.do([props.examination.id, candidate.id]).url,
        {},
        { preserveScroll: true },
    );
}

function reissue(candidate: CheckInRow): void {
    router.post(
        conduct.checkin.reissuePin([props.examination.id, candidate.id]).url,
        {},
        { preserveScroll: true },
    );
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
    <Head :title="`Check-in — ${examination.title}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Check-in — ${examination.title}`"
            :description="`${examination.reference} · ${examination.course}`"
        />

        <div
            v-if="pin.pin"
            class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200"
            data-test="issued-pin"
        >
            <span
                >Candidate <strong>{{ pin.pin.candidateNo }}</strong
                >'s exam PIN is
                <strong
                    class="font-mono text-lg tracking-widest"
                    data-test="pin-value"
                    >{{ pin.pin.pin }}</strong
                >
                — tell them now; it is shown here only once.</span
            >
        </div>

        <div
            v-if="pin.pins && pin.pins.length > 0"
            class="grid gap-2 rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-100"
            data-test="issued-pins"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <strong
                    >{{ pin.pins.length }} PINs issued — print or note them now;
                    they are shown only once.</strong
                >
                <Button size="sm" variant="outline" @click="printPins"
                    ><Printer /> Print</Button
                >
            </div>
            <table class="w-full text-left">
                <tbody>
                    <tr
                        v-for="row in pin.pins"
                        :key="row.candidateNo"
                        class="border-t border-emerald-200 dark:border-emerald-800"
                    >
                        <td class="py-1 font-mono">{{ row.candidateNo }}</td>
                        <td class="py-1">{{ row.name }}</td>
                        <td class="py-1 font-mono text-base tracking-widest">
                            {{ row.pin }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="shared"
            class="bg-muted/40 rounded-xl border p-4 text-sm"
            data-test="shared-pin"
        >
            Everyone signs in with their candidate number and the exam PIN
            <strong class="font-mono text-base">{{
                examination.sharedPin
            }}</strong
            >. Checking in only records who is here; nobody needs a seat or a
            PIN of their own.
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <span class="text-muted-foreground text-sm"
                >{{ counts.checkedIn }} checked in ·
                {{ waiting }} waiting<template
                    v-if="!shared && counts.enrolled > 0"
                >
                    · {{ counts.enrolled }} without a seat</template
                ></span
            >
            <Button
                :disabled="waiting === 0 || checkingAll"
                data-test="check-in-all"
                @click="checkInAll"
            >
                <Users /> Check in all ({{ waiting }})
            </Button>
            <Button
                v-if="selected.length > 0"
                variant="outline"
                :disabled="checkingAll"
                data-test="check-in-selected"
                @click="checkInSelected"
            >
                <UserCheck /> Check in selected ({{ selected.length }})
            </Button>
        </div>

        <form
            class="flex flex-wrap items-end gap-2 rounded-xl border p-4 shadow-xs"
            @submit.prevent="runSearch"
        >
            <Input
                v-model="query"
                placeholder="Candidate number, roll number or name"
                class="max-w-sm"
                data-test="checkin-search"
            />
            <Button type="submit" variant="outline" data-test="checkin-find"
                ><Search /> Find</Button
            >
        </form>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="w-8 px-3 py-2">
                            <Checkbox
                                :model-value="allTicked"
                                :disabled="selectable.length === 0"
                                aria-label="Tick everyone on this page"
                                data-test="tick-all"
                                @update:model-value="
                                    (on) => toggleAll(on === true)
                                "
                            />
                        </th>
                        <th class="px-3 py-2 font-medium">Candidate</th>
                        <th class="px-3 py-2 font-medium">Seat</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="candidate in results"
                        :key="candidate.id"
                        class="border-t align-top"
                        :data-candidate="candidate.id"
                    >
                        <td class="px-3 py-2">
                            <Checkbox
                                v-if="canCheckIn(candidate)"
                                :model-value="selected.includes(candidate.id)"
                                :aria-label="`Tick ${candidate.candidateNo}`"
                                @update:model-value="
                                    (on) => toggle(candidate.id, on === true)
                                "
                            />
                        </td>
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
                            <span v-if="candidate.room"
                                >{{ candidate.centre }} — {{ candidate.room
                                }}<span v-if="candidate.seatNo">
                                    · {{ candidate.seatNo }}</span
                                ></span
                            >
                            <span v-else class="text-muted-foreground"
                                >Not seated</span
                            >
                        </td>
                        <td class="px-3 py-2">
                            <Badge :variant="statusStyle[candidate.status]">{{
                                candidate.statusLabel
                            }}</Badge>
                        </td>
                        <td class="px-3 py-2">
                            <Button
                                v-if="canCheckIn(candidate)"
                                size="sm"
                                data-test="check-in"
                                @click="checkIn(candidate)"
                            >
                                <UserCheck />
                                {{
                                    shared ? 'Check in' : 'Check in & issue PIN'
                                }}
                            </Button>
                            <span
                                v-else-if="
                                    candidate.status === 'checked_in' && shared
                                "
                                class="text-muted-foreground text-xs"
                                >Checked in</span
                            >
                            <Button
                                v-else-if="candidate.status === 'checked_in'"
                                size="sm"
                                variant="outline"
                                data-test="reissue-pin"
                                @click="reissue(candidate)"
                            >
                                <KeyRound /> Reissue PIN
                            </Button>
                            <span v-else class="text-muted-foreground text-xs"
                                >Needs a seat first</span
                            >
                        </td>
                    </tr>
                    <tr v-if="results.length === 0 && search !== ''">
                        <td
                            colspan="5"
                            class="text-muted-foreground px-3 py-10 text-center"
                        >
                            No candidate matches "{{ search }}".
                        </td>
                    </tr>
                    <tr v-if="results.length === 0 && search === ''">
                        <td
                            colspan="5"
                            class="text-muted-foreground px-3 py-10 text-center"
                        >
                            No candidates yet: import them on the Candidates
                            page.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="pages.last > 1"
            class="flex items-center gap-2 text-sm"
            data-test="pages"
        >
            <Button
                size="sm"
                variant="outline"
                :disabled="pages.current === 1"
                @click="goTo(pages.current - 1)"
                >Previous</Button
            >
            <span class="text-muted-foreground"
                >Page {{ pages.current }} of {{ pages.last }} ·
                {{ pages.total }} candidates</span
            >
            <Button
                size="sm"
                variant="outline"
                :disabled="pages.current === pages.last"
                @click="goTo(pages.current + 1)"
                >Next</Button
            >
        </div>
    </div>
</template>
