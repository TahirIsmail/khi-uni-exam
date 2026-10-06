<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Clock, Pause, Play, Power } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import conduct from '@/routes/conduct';
import type {
    AttemptStatus,
    ExaminationDetail,
    MonitorRow,
    ProctorSeverity,
} from '@/types';

const props = defineProps<{
    examination: ExaminationDetail;
    attempts: MonitorRow[];
    pages: { current: number; last: number; total: number };
    summary: {
        notStarted: number;
        inProgress: number;
        paused: number;
        submitted: number;
        offline: number;
    };
    search: string;
}>();

const query = ref(props.search);
function show(pageNo: number): void {
    router.get(
        conduct.monitor(props.examination.id).url,
        {
            ...(query.value === '' ? {} : { search: query.value }),
            ...(pageNo > 1 ? { page: pageNo } : {}),
        },
        { preserveState: true, replace: true },
    );
}

defineOptions({
    layout: { breadcrumbs: [{ title: 'Conduct Exam', href: conduct.index() }] },
});

function endSession(attempt: MonitorRow): void {
    if (
        !window.confirm(
            `End ${attempt.candidateNo}'s open session? They will be able to sign in again, on any computer.`,
        )
    ) {
        return;
    }
    router.post(
        conduct.monitor.endSession([props.examination.id, attempt.id]).url,
        {},
        { preserveScroll: true },
    );
}

const editingTime = ref<number | null>(null);
const timeForm = useForm({ minutes: 10, reason: '' });
function addTime(attempt: MonitorRow): void {
    timeForm.post(
        conduct.monitor.addTime([props.examination.id, attempt.id]).url,
        {
            preserveScroll: true,
            onSuccess: () => {
                editingTime.value = null;
                timeForm.reset();
            },
        },
    );
}

function pauseRoom(roomId: number): void {
    router.post(
        conduct.monitor.pauseRoom([props.examination.id, roomId]).url,
        {},
        { preserveScroll: true },
    );
}

function resumeRoom(roomId: number): void {
    router.post(
        conduct.monitor.resumeRoom([props.examination.id, roomId]).url,
        {},
        { preserveScroll: true },
    );
}

const statusStyle: Record<AttemptStatus, 'secondary' | 'outline' | 'default'> =
    {
        not_started: 'secondary',
        in_progress: 'default',
        paused: 'outline',
        submitted: 'secondary',
        voided: 'secondary',
    };

const severityStyle: Record<
    ProctorSeverity,
    'outline' | 'secondary' | 'destructive'
> = {
    low: 'outline',
    medium: 'secondary',
    high: 'destructive',
};

// The clock ticks here every second from the last figures the server gave, and those are fetched
// again quietly every 20 seconds — never while a form on the page is open.
const loadedAt = ref(Date.now());
const now = ref(Date.now());
watch(
    () => props.attempts,
    () => (loadedAt.value = Date.now()),
);
function left(attempt: MonitorRow): number | null {
    if (attempt.remainingSeconds === null) {
        return null;
    }
    if (attempt.status !== 'in_progress') {
        return attempt.remainingSeconds;
    }

    return Math.max(
        0,
        attempt.remainingSeconds -
            Math.floor((now.value - loadedAt.value) / 1000),
    );
}
let tick: number | undefined;
let refresh: number | undefined;
onMounted(() => {
    tick = window.setInterval(() => (now.value = Date.now()), 1000);
    refresh = window.setInterval(() => {
        if (editingTime.value === null && !document.hidden) {
            router.reload({ only: ['attempts', 'pages', 'summary'] });
        }
    }, 20_000);
});
onBeforeUnmount(() => {
    window.clearInterval(tick);
    window.clearInterval(refresh);
});

function minutes(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;

    return `${m}:${s.toString().padStart(2, '0')}`;
}
</script>

<template>
    <Head :title="`Monitor — ${examination.title}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Monitor — ${examination.title}`"
            :description="`${examination.reference} · ${examination.course}`"
        />

        <div
            class="grid grid-cols-2 gap-3 sm:grid-cols-5"
            data-test="monitor-summary"
        >
            <div
                v-for="[label, value] in [
                    ['Writing', summary.inProgress],
                    ['Submitted', summary.submitted],
                    ['Not started', summary.notStarted],
                    ['Paused', summary.paused],
                    ['Writing, computer silent', summary.offline],
                ] as const"
                :key="label"
                class="rounded-xl border p-3 shadow-xs"
            >
                <div class="text-2xl font-semibold tabular-nums">
                    {{ value }}
                </div>
                <div class="text-muted-foreground text-xs">{{ label }}</div>
            </div>
        </div>

        <form class="flex flex-wrap gap-2" @submit.prevent="show(1)">
            <Input
                v-model="query"
                placeholder="Candidate number or name"
                class="max-w-xs"
            />
            <Button type="submit" variant="outline">Find</Button>
            <span class="text-muted-foreground self-center text-xs"
                >Updates by itself every 20 seconds.</span
            >
        </form>

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Candidate</th>
                        <th class="px-3 py-2 font-medium">Room</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">Remaining</th>
                        <th class="px-3 py-2 font-medium">Computer</th>
                        <th class="px-3 py-2 font-medium">Proctoring</th>
                        <th class="px-3 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="attempt in attempts"
                        :key="attempt.id"
                        class="border-t align-top"
                        :data-attempt="attempt.id"
                    >
                        <td class="px-3 py-2">
                            <div class="font-mono text-xs">
                                {{ attempt.candidateNo }}
                            </div>
                            <div class="font-medium">{{ attempt.name }}</div>
                            <Link
                                v-if="attempt.status !== 'not_started'"
                                :href="
                                    conduct.attempts.review([
                                        examination.id,
                                        attempt.id,
                                    ])
                                "
                                class="text-primary text-xs underline underline-offset-2"
                                data-test="review-answers"
                                >Answers</Link
                            >
                        </td>
                        <td class="px-3 py-2">
                            {{ attempt.room ?? '—' }}
                            <Button
                                v-if="
                                    attempt.roomId &&
                                    attempt.status === 'in_progress'
                                "
                                size="sm"
                                variant="ghost"
                                data-test="pause-room"
                                @click="pauseRoom(attempt.roomId)"
                            >
                                <Pause class="size-3" />
                            </Button>
                            <Button
                                v-if="
                                    attempt.roomId &&
                                    attempt.status === 'paused'
                                "
                                size="sm"
                                variant="ghost"
                                data-test="resume-room"
                                @click="resumeRoom(attempt.roomId)"
                            >
                                <Play class="size-3" />
                            </Button>
                        </td>
                        <td class="px-3 py-2">
                            <Badge :variant="statusStyle[attempt.status]">{{
                                attempt.statusLabel
                            }}</Badge>
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ minutes(left(attempt)) }}
                            <template v-if="editingTime === attempt.id">
                                <div class="mt-1 grid gap-1">
                                    <Input
                                        v-model="timeForm.minutes"
                                        type="number"
                                        min="1"
                                        max="180"
                                        class="h-8 w-20"
                                        data-test="add-minutes"
                                    />
                                    <Input
                                        v-model="timeForm.reason"
                                        placeholder="Reason"
                                        maxlength="300"
                                        class="h-8"
                                        data-test="add-reason"
                                    />
                                    <div class="flex gap-1">
                                        <Button
                                            size="sm"
                                            :disabled="timeForm.processing"
                                            data-test="save-add-time"
                                            @click="addTime(attempt)"
                                            >Add</Button
                                        >
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            @click="editingTime = null"
                                            >Cancel</Button
                                        >
                                    </div>
                                </div>
                            </template>
                            <Button
                                v-else-if="
                                    attempt.status === 'in_progress' ||
                                    attempt.status === 'paused'
                                "
                                size="sm"
                                variant="ghost"
                                data-test="edit-add-time"
                                @click="editingTime = attempt.id"
                            >
                                <Clock class="size-3" />
                            </Button>
                        </td>
                        <td class="px-3 py-2">
                            <span
                                v-if="attempt.sessionAlive"
                                class="text-green-600 dark:text-green-400"
                                >Connected</span
                            >
                            <span
                                v-else-if="attempt.hasOpenSession"
                                class="text-amber-600 dark:text-amber-400"
                                >Silent</span
                            >
                            <span v-else class="text-muted-foreground"
                                >Not signed in</span
                            >
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                v-if="attempt.proctorEventCount > 0"
                                :href="
                                    conduct.proctoring.case([
                                        examination.id,
                                        attempt.id,
                                    ])
                                "
                                class="inline-flex items-center gap-1"
                                data-test="proctor-events"
                            >
                                <Badge
                                    :variant="
                                        severityStyle[
                                            attempt.proctorHighestSeverity ??
                                                'low'
                                        ]
                                    "
                                >
                                    <AlertTriangle class="size-3" />
                                    {{ attempt.proctorEventCount }}
                                </Badge>
                            </Link>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-2">
                            <Button
                                v-if="attempt.hasOpenSession"
                                size="sm"
                                variant="outline"
                                data-test="end-session"
                                @click="endSession(attempt)"
                            >
                                <Power /> End session
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="attempts.length === 0">
                        <td
                            colspan="7"
                            class="text-muted-foreground px-3 py-10 text-center"
                        >
                            Nobody has started this exam yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="pages.last > 1"
            class="flex items-center gap-2 text-sm"
            data-test="monitor-pages"
        >
            <Button
                size="sm"
                variant="outline"
                :disabled="pages.current === 1"
                @click="show(pages.current - 1)"
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
                @click="show(pages.current + 1)"
                >Next</Button
            >
        </div>
    </div>
</template>
