<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import sit from '@/routes/sit';

const props = defineProps<{
    examination: { id: number; title: string; reference: string };
}>();

const form = useForm({ candidate_no: '', pin: '' });

// "session" (another computer already has this exam open) is not a field on this form — it comes
// back the same way EnsureDeliverySessionActive's own redirect reports it.
const page = usePage();
const sessionError = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.session,
);

function submit(): void {
    form.post(sit.login.store(props.examination.id).url);
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

            <form
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
