<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Building2, Check, ChevronDown } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { update } from '@/routes/branch';

const page = usePage();
const branch = computed(() => page.props.branch);

function switchTo(id: number): void {
    if (id === branch.value?.id) {
        return;
    }
    router.put(update.url(), { branch_id: id }, { preserveScroll: true });
}
</script>

<template>
    <div v-if="branch" class="flex items-center gap-2 text-sm">
        <!-- The campus selected in the CMS opens here; everything belongs to it. -->
        <DropdownMenu v-if="branch.options.length > 1">
            <DropdownMenuTrigger as-child>
                <Button variant="outline" size="sm" data-test="branch-switcher">
                    <Building2 />
                    {{ branch.name ?? 'No campus' }}
                    <ChevronDown />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-56">
                <DropdownMenuLabel>Working in</DropdownMenuLabel>
                <DropdownMenuItem
                    v-for="option in branch.options"
                    :key="option.id"
                    :data-branch="option.id"
                    class="cursor-pointer"
                    @click="switchTo(option.id)"
                >
                    <Check
                        class="size-4"
                        :class="option.id === branch.id ? '' : 'opacity-0'"
                    />
                    {{ option.name }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
        <span
            v-else
            class="text-muted-foreground flex items-center gap-1.5"
            data-test="branch-name"
        >
            <Building2 class="size-4" />
            {{ branch.name ?? 'No campus' }}
        </span>
    </div>
</template>
