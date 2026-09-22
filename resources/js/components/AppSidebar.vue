<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BadgeCheck,
    ClipboardCheck,
    ClipboardList,
    FileCheck,
    FileQuestion,
    LayoutGrid,
    Upload,
} from '@lucide/vue';
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
import { index as approvals } from '@/routes/approvals';
import { index as exams } from '@/routes/exams';
import { index as imports } from '@/routes/imports';
import { index as questions } from '@/routes/questions';
import { index as reviews } from '@/routes/reviews';
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

    if (page.props.auth.can?.reviewQuestions) {
        items.push({
            title: 'My reviews',
            href: reviews(),
            icon: ClipboardCheck,
        });
    }

    if (page.props.auth.can?.approveQuestions) {
        items.push({
            title: 'Approvals',
            href: approvals(),
            icon: BadgeCheck,
        });
    }

    if (page.props.auth.can?.importQuestions) {
        items.push({
            title: 'Import questions',
            href: imports(),
            icon: Upload,
        });
    }

    // Building an examination: its details, its blueprint and, next, its paper.
    if (page.props.auth.can?.viewExams) {
        items.push({
            title: 'Create exam',
            href: exams(),
            icon: ClipboardList,
        });
    }

    // Blueprints that somebody else wrote and is waiting on: the approvers' way in.
    if (page.props.auth.can?.approveBlueprints) {
        items.push({
            title: 'Exam approvals',
            href: '/exams?stage=submitted',
            icon: FileCheck,
            badge: page.props.auth.awaiting?.blueprints ?? 0,
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
