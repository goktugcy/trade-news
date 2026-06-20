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
import { useI18n } from 'vue-i18n';
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
const { t } = useI18n();
const isAdmin = computed(() => page.props.auth?.user?.is_admin === true);

const mainNavItems = computed<NavItem[]>(() => [
    { title: t('nav.dashboard'), href: '/dashboard', icon: LayoutGrid },
    { title: t('nav.allNews'), href: '/news', icon: Newspaper },
    { title: t('nav.watchlistNews'), href: '/news/watchlist', icon: Star },
    { title: t('nav.savedNews'), href: '/news/saved', icon: Bookmark },
    { title: t('nav.stocks'), href: '/stocks', icon: TrendingUp },
    { title: t('nav.watchlist'), href: '/watchlist', icon: ListChecks },
    { title: t('nav.alerts'), href: '/alerts', icon: Bell },
    { title: t('nav.notifications'), href: '/notifications', icon: Inbox },
]);

const settingsNavItems = computed<NavItem[]>(() => [
    { title: t('nav.telegram'), href: '/settings/telegram', icon: Send },
    { title: t('nav.settings'), href: '/settings/profile', icon: Settings2 },
]);

const adminNavItems = computed<NavItem[]>(() => [
    { title: t('nav.admin'), href: '/admin', icon: Shield },
]);
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
            <NavMain :items="mainNavItems" :label="t('nav.platform')" />
            <NavMain :items="settingsNavItems" :label="t('nav.account')" />
            <NavMain v-if="isAdmin" :items="adminNavItems" :label="t('nav.administration')" />
        </SidebarContent>

        <SidebarFooter>
            <NavThemeToggle />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
