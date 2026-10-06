<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/login';

setLayoutProps({
    title: 'Sign in',
    description:
        'Sign in with the email and password the administrator gave you.',
});
</script>

<template>
    <Head title="Sign in" />

    <Form
        v-bind="store.form()"
        class="space-y-4"
        :reset-on-error="['password']"
        #default="{ errors, processing }"
    >
        <div class="grid gap-1.5">
            <Label for="email">Email</Label>
            <Input
                id="email"
                name="email"
                type="email"
                autocomplete="username"
                autofocus
                required
                data-test="login-email"
            />
            <InputError :message="errors.email" />
        </div>

        <div class="grid gap-1.5">
            <Label for="password">Password</Label>
            <Input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
                data-test="login-password"
            />
            <InputError :message="errors.password" />
        </div>

        <Button
            type="submit"
            class="w-full"
            :disabled="processing"
            data-test="login-submit"
            >Sign in</Button
        >
    </Form>
</template>
