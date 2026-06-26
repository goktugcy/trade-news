<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Check, Languages, LogOut, Settings } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { i18n, normalizeLocale } from '@/i18n';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

defineProps<Props>();

const { t, locale } = useI18n();

const handleLogout = () => {
    router.flushAll();
};

function setLocale(value: unknown): void {
    const next = normalizeLocale(value);

    if (next === i18n.global.locale.value) {
        return;
    }

    // Flip the UI language instantly, then persist the choice in the background.
    i18n.global.locale.value = next;
    router.patch('/settings/locale', { locale: next }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                {{ t('nav.settings') }}
            </Link>
        </DropdownMenuItem>
        <DropdownMenuSub>
            <DropdownMenuSubTrigger class="cursor-pointer">
                <Languages class="mr-2 h-4 w-4" />
                {{ t('nav.language') }}
            </DropdownMenuSubTrigger>
            <DropdownMenuSubContent>
                <DropdownMenuItem class="cursor-pointer gap-2" @select="setLocale('en')">
                    <span class="text-base leading-none">🇬🇧</span>
                    {{ t('onboarding.english') }}
                    <Check v-if="locale === 'en'" class="ml-auto h-4 w-4" />
                </DropdownMenuItem>
                <DropdownMenuItem class="cursor-pointer gap-2" @select="setLocale('tr')">
                    <span class="text-base leading-none">🇹🇷</span>
                    {{ t('onboarding.turkish') }}
                    <Check v-if="locale === 'tr'" class="ml-auto h-4 w-4" />
                </DropdownMenuItem>
            </DropdownMenuSubContent>
        </DropdownMenuSub>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            {{ t('nav.logout') }}
        </Link>
    </DropdownMenuItem>
</template>
