<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Flag } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AnswerCapture from '@/components/sit/AnswerCapture.vue';
import DeviceApprovalWait from '@/components/sit/DeviceApprovalWait.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import sit from '@/routes/sit';
import type {
    AnswerPayload,
    AttemptItem,
    AttemptState,
    ProctorEventType,
} from '@/types';

const props = defineProps<{
    examination: { id: number; title: string; instructions: string | null };
    attempt: AttemptState;
    items: AttemptItem[];
}>();

// ---- local, per-attempt state: the answers as they stand, and a "Saved" indicator per item ------
const answers = ref<
    Record<number, { answer: AnswerPayload | null; flagged: boolean }>
>(
    Object.fromEntries(
        props.items.map((item) => [
            item.id,
            { answer: item.answer, flagged: item.flagged },
        ]),
    ),
);
// Only an item with something to save gets a status at all; `false` while a change is still
// queued, `true` once every queued change for it has been acknowledged.
const savedUpTo = ref<Record<number, boolean>>(
    Object.fromEntries(
        props.items
            .filter((item) => item.answer !== null)
            .map((item) => [item.id, true]),
    ),
);
const currentIndex = ref(
    Math.max(
        0,
        props.items.findIndex((item) => item.id === props.attempt.lastItemId),
    ),
);
const currentItem = computed<AttemptItem | undefined>(
    () => props.items[currentIndex.value],
);

function goTo(index: number): void {
    currentIndex.value = index;
}

// ---- the timer, ticking locally and corrected by every heartbeat --------------------------------
const remainingSeconds = ref(props.attempt.remainingSeconds ?? 0);
const paused = ref(props.attempt.status === 'paused');
const timeLabel = computed(() => {
    const s = Math.max(0, remainingSeconds.value);

    return `${Math.floor(s / 60)}:${(s % 60).toString().padStart(2, '0')}`;
});

// ---- CSRF, the same way every other plain fetch() call in this app reads it ---------------------
function csrf(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
}
async function post(
    path: string,
    body: Record<string, unknown>,
): Promise<Response> {
    return fetch(path, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrf(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
}

// ---- the answer journal: kept in this browser until the server has acknowledged it (ADR-0003) ---
type QueueEntry = {
    sequence: number;
    item_id: number;
    payload: AnswerPayload;
    flagged: boolean;
    client_time: string;
};
const journalKey = `sit-journal-${props.examination.id}`;
const sequenceKey = `sit-sequence-${props.examination.id}`;
let nextSequence = Number(localStorage.getItem(sequenceKey) ?? '0') + 1;
function loadQueue(): QueueEntry[] {
    try {
        return JSON.parse(
            localStorage.getItem(journalKey) ?? '[]',
        ) as QueueEntry[];
    } catch {
        return [];
    }
}
function saveQueue(queue: QueueEntry[]): void {
    localStorage.setItem(journalKey, JSON.stringify(queue));
}
let flushing = false;
async function flushQueue(): Promise<void> {
    if (flushing) {
        return;
    }
    flushing = true;
    try {
        // The queue is re-read from storage on every turn, not captured once at the start: an
        // entry queued while this loop is already running (flagging right after answering, say)
        // must still be picked up by this same run, not stranded until something else calls this.
        while (true) {
            const queue = loadQueue();
            if (queue.length === 0) {
                break;
            }
            const entry = queue[0];
            try {
                const response = await post(
                    sit.answer(props.examination.id).url,
                    entry,
                );
                if (!response.ok) {
                    break;
                }
            } catch {
                break; // offline: stop here and try again on the next tick.
            }
            savedUpTo.value = { ...savedUpTo.value, [entry.item_id]: true };
            // Read again, right before writing: another change may have been queued while that
            // request was in flight, and slicing the array captured before the await would
            // silently throw it away instead of leaving it for the next turn of this loop.
            saveQueue(
                loadQueue().filter(
                    (queued) => queued.sequence !== entry.sequence,
                ),
            );
        }
    } finally {
        flushing = false;
    }
}

function queueAnswer(
    item: AttemptItem,
    payload: AnswerPayload,
    flagged: boolean,
): void {
    answers.value = {
        ...answers.value,
        [item.id]: { answer: payload, flagged },
    };
    savedUpTo.value = { ...savedUpTo.value, [item.id]: false };

    const sequence = nextSequence++;
    localStorage.setItem(sequenceKey, String(sequence));
    const entry: QueueEntry = {
        sequence,
        item_id: item.id,
        payload,
        flagged,
        client_time: new Date().toISOString(),
    };
    const queue = loadQueue();
    queue.push(entry);
    saveQueue(queue);

    void flushQueue();
}

function onAnswerChange(item: AttemptItem, payload: AnswerPayload): void {
    const merged = { ...answers.value[item.id]?.answer, ...payload };
    queueAnswer(item, merged, answers.value[item.id]?.flagged ?? false);
}

function onFlagChange(item: AttemptItem, flagged: boolean): void {
    queueAnswer(item, answers.value[item.id]?.answer ?? {}, flagged);
}

// ---- the heartbeat: "still here", and how the timer stays right even between answers -------------
let heartbeatTimer: number | undefined;
async function heartbeat(): Promise<void> {
    try {
        const response = await post(
            sit.heartbeat(props.examination.id).url,
            {},
        );
        if (response.status === 422) {
            // Ended elsewhere: the next load will send them to sign in again.
            window.location.href = sit.login(props.examination.id).url;

            return;
        }
        const data = (await response.json()) as {
            status: string;
            remainingSeconds: number | null;
        };
        remainingSeconds.value =
            data.remainingSeconds ?? remainingSeconds.value;
        paused.value = data.status === 'paused';
        if (data.status === 'submitted') {
            window.location.href = sit.submitted(props.examination.id).url;
        }
    } catch {
        // Offline: the local timer keeps counting down until the next successful heartbeat.
    }
}

let tickTimer: number | undefined;

// ---- centre device approval (ADR-0003's own lockdown step, exam phase step 19) ------------------
const deviceWaiting = ref(false);
function deviceFingerprint(): string {
    return [
        navigator.userAgent,
        `${screen.width}x${screen.height}`,
        Intl.DateTimeFormat().resolvedOptions().timeZone,
    ].join('|');
}
let deviceCheckTimer: number | undefined;
async function checkDevice(): Promise<void> {
    try {
        const response = await post(sit.device(props.examination.id).url, {
            fingerprint: deviceFingerprint(),
        });
        const data = (await response.json()) as { status: string };
        deviceWaiting.value = data.status === 'pending';
    } catch {
        // Offline: try again on the next tick rather than blocking on a network error.
    }
    if (deviceWaiting.value) {
        deviceCheckTimer = window.setTimeout(() => void checkDevice(), 5000);
    } else {
        startLockdown();
    }
}

// ---- browser lockdown (exam phase step 19): fullscreen, and every attempt to leave it, copy, ----
// paste, right-click, print or open developer tools is reported, never silently blocked alone.
function reportProctorEvent(type: ProctorEventType, detail?: Record<string, unknown>): void {
    void post(sit.proctorEvent(props.examination.id).url, { type, detail });
}
function onVisibilityChange(): void {
    if (document.hidden) {
        reportProctorEvent('tab_hidden');
    }
}
function onFullscreenChange(): void {
    if (document.fullscreenElement === null) {
        reportProctorEvent('fullscreen_exited');
    }
}
function onContextMenu(event: MouseEvent): void {
    event.preventDefault();
    reportProctorEvent('right_click');
}
function onCopy(event: ClipboardEvent): void {
    event.preventDefault();
    reportProctorEvent('copy_attempt');
}
function onPaste(event: ClipboardEvent): void {
    event.preventDefault();
    reportProctorEvent('paste_attempt');
}
function onBeforePrint(): void {
    reportProctorEvent('print_attempt');
}
function onKeydown(event: KeyboardEvent): void {
    const isDevtoolsShortcut =
        event.key === 'F12' ||
        ((event.ctrlKey || event.metaKey) &&
            event.shiftKey &&
            ['I', 'J', 'C'].includes(event.key.toUpperCase()));
    if (isDevtoolsShortcut) {
        event.preventDefault();
        reportProctorEvent('devtools_opened');
    }
}
function startLockdown(): void {
    document.documentElement.requestFullscreen?.().catch(() => undefined);
    document.addEventListener('visibilitychange', onVisibilityChange);
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('contextmenu', onContextMenu);
    document.addEventListener('copy', onCopy);
    document.addEventListener('paste', onPaste);
    document.addEventListener('keydown', onKeydown);
    window.addEventListener('beforeprint', onBeforePrint);
}
function stopLockdown(): void {
    document.removeEventListener('visibilitychange', onVisibilityChange);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    document.removeEventListener('contextmenu', onContextMenu);
    document.removeEventListener('copy', onCopy);
    document.removeEventListener('paste', onPaste);
    document.removeEventListener('keydown', onKeydown);
    window.removeEventListener('beforeprint', onBeforePrint);
}

onMounted(() => {
    void flushQueue();
    void heartbeat();
    void checkDevice();
    heartbeatTimer = window.setInterval(() => void heartbeat(), 20000);
    tickTimer = window.setInterval(() => {
        if (!paused.value) {
            remainingSeconds.value = Math.max(0, remainingSeconds.value - 1);
        }
    }, 1000);
    window.addEventListener('online', flushQueue);
});
onBeforeUnmount(() => {
    window.clearInterval(heartbeatTimer);
    window.clearInterval(tickTimer);
    window.clearTimeout(deviceCheckTimer);
    window.removeEventListener('online', flushQueue);
    stopLockdown();
});

// ---- submitting ---------------------------------------------------------------------------------
const submitting = ref(false);
async function submit(): Promise<void> {
    if (
        !window.confirm(
            'Submit this exam? Once submitted, it cannot be changed.',
        )
    ) {
        return;
    }
    submitting.value = true;
    await flushQueue();
    router.post(sit.submit(props.examination.id).url);
}

const answeredCount = computed(
    () =>
        props.items.filter((item) => {
            const a = answers.value[item.id]?.answer;

            return a !== null && a !== undefined && Object.keys(a).length > 0;
        }).length,
);
</script>

<template>
    <Head :title="examination.title" />

    <DeviceApprovalWait
        v-if="deviceWaiting"
        :examination-title="examination.title"
    />

    <div class="bg-background flex min-h-screen flex-col">
        <header
            class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
        >
            <div>
                <h1 class="font-medium">{{ examination.title }}</h1>
                <p class="text-muted-foreground text-xs">
                    {{ answeredCount }} of {{ items.length }} answered
                </p>
            </div>
            <div class="flex items-center gap-3">
                <Badge
                    v-if="paused"
                    variant="secondary"
                    data-test="paused-banner"
                    >Paused — please wait</Badge
                >
                <span
                    class="font-mono text-lg tabular-nums"
                    data-test="remaining-time"
                    >{{ timeLabel }}</span
                >
                <Button
                    :disabled="submitting"
                    data-test="submit-exam"
                    @click="submit"
                    >Submit</Button
                >
            </div>
        </header>

        <div class="grid flex-1 grid-cols-[auto_1fr] gap-4 p-4">
            <nav class="grid content-start gap-1" data-test="item-nav">
                <button
                    v-for="(item, index) in items"
                    :key="item.id"
                    type="button"
                    class="flex size-9 items-center justify-center rounded-md border text-sm"
                    :class="[
                        index === currentIndex
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'hover:bg-accent',
                    ]"
                    :data-item-nav="item.id"
                    @click="goTo(index)"
                >
                    {{ item.position }}
                    <Flag
                        v-if="answers[item.id]?.flagged"
                        class="absolute size-2.5 translate-x-3 -translate-y-3"
                    />
                </button>
            </nav>

            <div v-if="currentItem" class="grid gap-4">
                <AnswerCapture
                    :item="currentItem"
                    :answer="answers[currentItem.id]?.answer ?? null"
                    :flagged="answers[currentItem.id]?.flagged ?? false"
                    @change="(payload) => onAnswerChange(currentItem!, payload)"
                    @flag="(flagged) => onFlagChange(currentItem!, flagged)"
                />
                <p
                    v-if="currentItem.id in savedUpTo"
                    class="text-muted-foreground text-xs"
                    data-test="save-status"
                >
                    {{
                        savedUpTo[currentItem.id] === false
                            ? 'Saving…'
                            : 'Saved'
                    }}
                </p>
                <div class="flex justify-between">
                    <Button
                        variant="outline"
                        :disabled="currentIndex === 0"
                        @click="goTo(currentIndex - 1)"
                        >Previous</Button
                    >
                    <Button
                        variant="outline"
                        :disabled="currentIndex === items.length - 1"
                        @click="goTo(currentIndex + 1)"
                        >Next</Button
                    >
                </div>
            </div>
        </div>
    </div>
</template>
