<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { ref, watchEffect } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { logout } from '@/routes';
import { store } from '@/routes/mfa/challenge';

const props = defineProps<{
    confirming: boolean;
}>();

const useRecovery = ref(false);
const code = ref('');

watchEffect(() => {
    setLayoutProps({
        title: useRecovery.value ? 'Recovery code' : 'Authentication code',
        description: props.confirming
            ? 'Confirm it is you before changing security settings.'
            : useRecovery.value
              ? 'Enter one of the recovery codes you saved when you set up your authenticator. Each code works once.'
              : 'Your account can change important data, so enter the 6-digit code from your authenticator app.',
    });
});

function toggle(clearErrors: () => void): void {
    useRecovery.value = !useRecovery.value;
    code.value = '';
    clearErrors();
}
</script>

<template>
    <Head title="Two-factor authentication" />

    <div class="space-y-6">
        <Form
            v-bind="store.form()"
            class="space-y-4"
            reset-on-error
            @error="code = ''"
            #default="{ errors, processing, clearErrors }"
        >
            <template v-if="!useRecovery">
                <input type="hidden" name="code" :value="code" />
                <div class="flex flex-col items-center space-y-3 text-center">
                    <InputOTP
                        id="otp"
                        v-model="code"
                        :maxlength="6"
                        :disabled="processing"
                        autofocus
                        inputmode="numeric"
                        autocomplete="one-time-code"
                    >
                        <InputOTPGroup>
                            <InputOTPSlot
                                v-for="index in 6"
                                :key="index"
                                :index="index - 1"
                            />
                        </InputOTPGroup>
                    </InputOTP>
                    <InputError :message="errors.code" />
                </div>
            </template>
            <template v-else>
                <Input
                    name="recovery_code"
                    type="text"
                    maxlength="64"
                    autocomplete="off"
                    placeholder="xxxxxxxxxx-xxxxxxxxxx"
                    autofocus
                    required
                />
                <InputError :message="errors.recovery_code" />
            </template>

            <Button
                type="submit"
                class="w-full"
                :disabled="processing || (!useRecovery && code.length !== 6)"
                data-test="mfa-continue"
                >Continue</Button
            >

            <div class="text-muted-foreground text-center text-sm">
                <button
                    type="button"
                    class="text-foreground underline underline-offset-4"
                    @click="toggle(clearErrors)"
                >
                    {{
                        useRecovery
                            ? 'Use the authenticator app instead'
                            : 'Lost your phone? Use a recovery code'
                    }}
                </button>
            </div>
        </Form>

        <p class="text-muted-foreground text-center text-sm">
            No phone and no recovery codes? Ask the system administrator to
            reset your authenticator.
            <Link
                :href="logout()"
                as="button"
                class="text-foreground underline underline-offset-4"
                >Log out</Link
            >
        </p>
    </div>
</template>
