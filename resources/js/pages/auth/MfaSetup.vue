<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { useAppearance } from '@/composables/useAppearance';
import { logout } from '@/routes';
import { confirm, start } from '@/routes/mfa/setup';

const props = defineProps<{
    required: boolean;
    started: boolean;
    qrCodeSvg: string | null;
    setupKey: string | null;
}>();

setLayoutProps({
    title: 'Set up two-factor authentication',
    description: props.required
        ? 'Your account can change important data (questions, exams, results or access), so it must be protected with an authenticator app before you continue.'
        : 'Protect your account with an authenticator app.',
});

const { resolvedAppearance } = useAppearance();
const code = ref('');
</script>

<template>
    <Head title="Set up two-factor authentication" />

    <div class="space-y-6">
        <template v-if="!started">
            <ol
                class="text-muted-foreground list-decimal space-y-1 pl-5 text-sm"
            >
                <li>
                    Install an authenticator app on your phone (Google
                    Authenticator, Microsoft Authenticator or similar).
                </li>
                <li>Scan the QR code shown on the next step.</li>
                <li>Enter the 6-digit code the app shows.</li>
            </ol>
            <Form v-bind="start.form()" #default="{ processing }">
                <Button
                    type="submit"
                    class="w-full"
                    :disabled="processing"
                    data-test="mfa-start"
                    >Start</Button
                >
            </Form>
        </template>

        <template v-else>
            <div
                class="mx-auto aspect-square w-56 overflow-hidden rounded-lg border bg-white p-4"
                aria-label="QR code for your authenticator app"
                role="img"
            >
                <!-- SVG generated on the server from this account's secret; contains no user input. -->
                <div
                    class="flex size-full items-center justify-center"
                    :style="{
                        filter:
                            resolvedAppearance === 'dark'
                                ? 'invert(1) brightness(1.5)'
                                : undefined,
                    }"
                    v-html="qrCodeSvg"
                />
            </div>
            <p class="text-muted-foreground text-center text-sm">
                Can't scan? Enter this key in the app:
                <code
                    class="text-foreground block font-mono break-all select-all"
                    data-test="mfa-setup-key"
                    >{{ setupKey }}</code
                >
            </p>

            <Form
                v-bind="confirm.form()"
                class="space-y-4"
                reset-on-error
                @error="code = ''"
                #default="{ errors, processing }"
            >
                <input type="hidden" name="code" :value="code" />
                <div class="flex flex-col items-center space-y-3">
                    <InputOTP
                        id="otp"
                        v-model="code"
                        :maxlength="6"
                        :disabled="processing"
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
                <Button
                    type="submit"
                    class="w-full"
                    :disabled="processing || code.length !== 6"
                    data-test="mfa-confirm"
                    >Confirm</Button
                >
            </Form>

            <Form
                v-bind="start.form()"
                class="text-center"
                #default="{ processing }"
            >
                <button
                    type="submit"
                    class="text-muted-foreground text-sm underline underline-offset-4"
                    :disabled="processing"
                >
                    Start again with a new QR code
                </button>
            </Form>
        </template>

        <p class="text-center text-sm">
            <Link
                :href="logout()"
                as="button"
                class="text-muted-foreground underline underline-offset-4"
                >Log out</Link
            >
        </p>
    </div>
</template>
