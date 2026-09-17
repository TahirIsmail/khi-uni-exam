<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutGrid, ScrollText, ShieldCheck, Users } from '@lucide/vue';
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
import { index as auditIndex } from '@/routes/admin/audit';
import { index as rolesIndex } from '@/routes/admin/roles';
import { index as staffIndex } from '@/routes/admin/staff';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

// Shown only when the server says so; every route checks the permission again.
const adminNavItems = computed<NavItem[]>(() => {
    const can = page.props.auth.can ?? {};
    const items: NavItem[] = [];
    if (can.manageRoles) {
        items.push({
            title: 'Roles & permissions',
            href: rolesIndex(),
            icon: ShieldCheck,
        });
    }
    if (can.manageStaff) {
        items.push({ title: 'Staff scopes', href: staffIndex(), icon: Users });
    }
    if (can.viewAudit) {
        items.push({
            title: 'Audit log',
            href: auditIndex(),
            icon: ScrollText,
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
            <NavMain
                v-if="adminNavItems.length > 0"
                label="Administration"
                :items="adminNavItems"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
