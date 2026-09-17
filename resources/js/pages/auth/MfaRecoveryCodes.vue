<script setup lang="ts">
import { Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

defineProps<{
    codes: string[];
    continueUrl: string;
}>();

setLayoutProps({
    title: 'Save your recovery codes',
    description:
        'If you lose your phone, each of these codes lets you sign in once. They are shown only now: print them or keep them somewhere safe, not on your phone.',
});

const saved = ref(false);
</script>

<template>
    <Head title="Recovery codes" />

    <div class="space-y-6">
        <ul
            class="bg-muted grid grid-cols-1 gap-2 rounded-lg p-4 font-mono text-sm sm:grid-cols-2"
            data-test="mfa-recovery-codes"
        >
            <li v-for="code in codes" :key="code" class="select-all">
                {{ code }}
            </li>
        </ul>

        <div class="flex items-center gap-3">
            <Checkbox id="saved" v-model="saved" />
            <Label for="saved">I have saved these codes</Label>
        </div>

        <Button as-child class="w-full" :disabled="!saved">
            <Link
                v-if="saved"
                :href="continueUrl"
                data-test="mfa-recovery-continue"
                >Continue</Link
            >
            <span v-else>Continue</span>
        </Button>
    </div>
</template>
