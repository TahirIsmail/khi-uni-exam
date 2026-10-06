<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import sit from '@/routes/sit';

const props = defineProps<{
    examination: { id: number; code: string; title: string; reference: string };
    opening: {
        state: 'open' | 'not_yet' | 'closed' | 'not_published';
        opensAt: string | null;
        closesAt: string | null;
        secondsToOpen: number | null;
    };
}>();

// Before it opens: a countdown, and the page opens the sign-in by itself when the time comes.
const left = ref(props.opening.secondsToOpen ?? 0);
const countdown = computed(() => {
    const s = Math.max(0, left.value);
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    const pad = (n: number): string => n.toString().padStart(2, '0');

    return h > 0 ? `${h}:${pad(m)}:${pad(sec)}` : `${m}:${pad(sec)}`;
});
let timer: number | undefined;
onMounted(() => {
    if (props.opening.state !== 'not_yet') {
        return;
    }
    timer = window.setInterval(() => {
        left.value -= 1;
        if (left.value <= 0) {
            window.clearInterval(timer);
            router.reload();
        }
    }, 1000);
});
onBeforeUnmount(() => window.clearInterval(timer));

const form = useForm({ candidate_no: '', pin: '' });

// "session" (another computer already has this exam open) is not a field on this form — it comes
// back the same way EnsureDeliverySessionActive's own redirect reports it.
const page = usePage();
const sessionError = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.session,
);

function submit(): void {
    form.post(sit.login.store(props.examination.code).url);
}
</script>

<template>
    <Head :title="`Sign in — ${examination.title}`" />

    <div
        class="bg-background flex min-h-screen items-center justify-center p-4"
    >
        <div class="w-full max-w-sm rounded-xl border p-6 shadow-xs">
            <h1 class="text-lg font-medium">{{ examination.title }}</h1>
            <p class="text-muted-foreground mt-1 text-sm">
                {{ examination.reference }}
            </p>

            <div
                v-if="opening.state === 'not_yet'"
                class="mt-6 grid gap-2 rounded-lg bg-amber-50 p-4 text-center text-amber-950 dark:bg-amber-950 dark:text-amber-100"
                data-test="not-yet"
            >
                <p class="font-medium">The exam has not started yet.</p>
                <p class="text-sm">It opens on {{ opening.opensAt }}.</p>
                <p class="font-mono text-2xl tabular-nums">{{ countdown }}</p>
                <p class="text-xs opacity-80">
                    Keep this page open: sign-in appears here by itself when the
                    exam starts.
                </p>
            </div>
            <div
                v-else-if="opening.state === 'closed'"
                class="bg-muted mt-6 grid gap-1 rounded-lg p-4 text-center"
                data-test="closed"
            >
                <p class="font-medium">This exam has ended.</p>
                <p class="text-muted-foreground text-sm">
                    It closed on {{ opening.closesAt }}.
                </p>
            </div>
            <div
                v-else-if="opening.state === 'not_published'"
                class="bg-muted mt-6 grid gap-1 rounded-lg p-4 text-center"
                data-test="not-published"
            >
                <p class="font-medium">This exam is not open yet.</p>
                <p class="text-muted-foreground text-sm">
                    Please wait for your invigilator, then refresh this page.
                </p>
            </div>
            <form
                v-else
                class="mt-6 grid gap-4"
                data-test="sit-login-form"
                @submit.prevent="submit"
            >
                <div class="grid gap-1.5">
                    <Label for="candidate_no">Candidate number</Label>
                    <Input
                        id="candidate_no"
                        v-model="form.candidate_no"
                        autofocus
                        autocomplete="off"
                        maxlength="30"
                        data-test="candidate-no"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="pin">Exam PIN</Label>
                    <Input
                        id="pin"
                        v-model="form.pin"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        maxlength="10"
                        data-test="pin"
                    />
                </div>
                <p
                    v-if="form.errors.pin"
                    class="text-destructive text-sm"
                    data-test="pin-error"
                >
                    {{ form.errors.pin }}
                </p>
                <p
                    v-if="sessionError"
                    class="text-destructive text-sm"
                    data-test="session-error"
                >
                    {{ sessionError }}
                </p>
                <Button
                    type="submit"
                    :disabled="form.processing"
                    data-test="sit-login-submit"
                >
                    Begin
                </Button>
            </form>
        </div>
    </div>
</template>
