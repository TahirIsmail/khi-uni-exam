<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BadgeCheck,
    ClipboardCheck,
    ClipboardList,
    FileCheck,
    FileQuestion,
    FileChartColumn,
    LayoutGrid,
    PenLine,
    Trophy,
    Users,
    Settings,
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
import conduct from '@/routes/conduct';
import marking from '@/routes/marking';
import reports from '@/routes/reports';
import results from '@/routes/results';
import { index as exams } from '@/routes/exams';
import { index as imports } from '@/routes/imports';
import { index as questions } from '@/routes/questions';
import { index as reviews } from '@/routes/reviews';
import { index as setup } from '@/routes/setup';
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

    // Running the examination once its paper is ready: in kmu-assess these open from the CMS's
    // Exams menu; here they sit in the app's own menu.
    if (page.props.auth.can?.conductExams) {
        items.push({
            title: 'Conduct exam',
            href: conduct.index(),
            icon: Users,
        });
    }

    if (page.props.auth.can?.markExams) {
        items.push({ title: 'Marking', href: marking.index(), icon: PenLine });
    }

    if (page.props.auth.can?.viewResults) {
        items.push({ title: 'Results', href: results.index(), icon: Trophy });
    }

    if (page.props.auth.can?.viewReports) {
        items.push({
            title: 'Reports',
            href: reports.index(),
            icon: FileChartColumn,
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
            <SidebarMenu v-if="page.props.auth.can?.manageSetup">
                <SidebarMenuItem>
                    <!-- Staff, roles, programmes, courses and settings. -->
                    <SidebarMenuButton as-child tooltip="Setup">
                        <Link :href="setup()" data-test="setup">
                            <Settings />
                            <span>Setup</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
