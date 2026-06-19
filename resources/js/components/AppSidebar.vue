<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Bell,
    Bookmark,
    Inbox,
    LayoutGrid,
    ListChecks,
    Newspaper,
    Send,
    Settings2,
    Shield,
    Star,
    TrendingUp,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavThemeToggle from '@/components/NavThemeToggle.vue';
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
import type { NavItem } from '@/types';

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.is_admin === true);

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
    { title: 'All News', href: '/news', icon: Newspaper },
    { title: 'Watchlist News', href: '/news/watchlist', icon: Star },
    { title: 'Saved News', href: '/news/saved', icon: Bookmark },
    { title: 'Stocks', href: '/stocks', icon: TrendingUp },
    { title: 'Watchlist', href: '/watchlist', icon: ListChecks },
    { title: 'Alerts', href: '/alerts', icon: Bell },
    { title: 'Notifications', href: '/notifications', icon: Inbox },
];

const settingsNavItems: NavItem[] = [
    { title: 'Telegram', href: '/settings/telegram', icon: Send },
    { title: 'Settings', href: '/settings/profile', icon: Settings2 },
];

const adminNavItems: NavItem[] = [{ title: 'Admin', href: '/admin', icon: Shield }];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link href="/dashboard">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" label="Platform" />
            <NavMain :items="settingsNavItems" label="Account" />
            <NavMain v-if="isAdmin" :items="adminNavItems" label="Administration" />
        </SidebarContent>

        <SidebarFooter>
            <NavThemeToggle />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
