<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Clock, Pause, Play, Power } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import conduct from '@/routes/conduct';
import type { AttemptStatus, ExaminationDetail, MonitorRow } from '@/types';

const props = defineProps<{
    examination: ExaminationDetail;
    attempts: MonitorRow[];
}>();

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
    };

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

        <div class="overflow-x-auto rounded-xl border shadow-xs">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Candidate</th>
                        <th class="px-3 py-2 font-medium">Room</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">Remaining</th>
                        <th class="px-3 py-2 font-medium">Computer</th>
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
                            {{ minutes(attempt.remainingSeconds) }}
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
                            colspan="6"
                            class="text-muted-foreground px-3 py-10 text-center"
                        >
                            Nobody has started this exam yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
