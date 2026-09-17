<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

defineOptions({
    layout: {
        title: 'Sign-in link not accepted',
        description:
            'The link from the CMS was invalid, expired or already used.',
    },
});

defineProps<{
    cmsUrl: string | null;
}>();
</script>

<template>
    <Head title="Sign-in link not accepted" />

    <div class="flex flex-col gap-4 text-center text-sm">
        <p>
            Go back to the CMS and open Assessment again. Each link works once
            and only for one minute.
        </p>
        <Button v-if="cmsUrl" as-child>
            <a :href="cmsUrl" data-test="back-to-cms">Back to the CMS</a>
        </Button>
    </div>
</template>
