<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, FileQuestion, LayoutGrid } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as questions } from '@/routes/questions';
import type { NavItem } from '@/types';

const page = usePage();

// Shown only when the server says the user may open it; the routes check again.
const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    ];

    if (page.props.auth.can?.viewQuestions) {
        items.push({
            title: 'Question bank',
            href: questions(),
            icon: FileQuestion,
        });
    }

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu>
                <SidebarMenuItem>
                    <!-- Roles, exam access, settings and the audit log are managed in the CMS. -->
                    <SidebarMenuButton as-child tooltip="Back to CMS">
                        <a :href="page.props.cmsUrl" data-test="back-to-cms">
                            <ArrowLeft />
                            <span>Back to CMS</span>
                        </a>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
