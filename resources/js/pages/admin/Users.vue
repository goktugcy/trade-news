<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { ref, watch } from 'vue';
import AdminNav from '@/components/tradenews/AdminNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type User = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    watchlist_count: number;
    rules_count: number;
    created_at: string | null;
};

const props = defineProps<{
    users: {
        data: User[];
        current_page: number;
        last_page: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    filters: { q: string | null };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin', href: '/admin' }] },
});

const search = ref(props.filters.q ?? '');
let debounce: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        const query: Record<string, string> = {};
        if (value) {
            query.q = value;
        }
        router.get('/admin/users', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
});

function toggleAdmin(id: number) {
    router.patch('/admin/users/' + id + '/admin', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Users" />

    <div class="mx-auto flex w-full flex-1 flex-col gap-4 p-4">
        <AdminNav />

        <div class="flex flex-wrap items-center gap-3">
            <Input v-model="search" type="search" placeholder="Search users…" class="max-w-xs" />
        </div>

        <div class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-sidebar-border/70 text-left text-xs uppercase tracking-wide text-muted-foreground dark:border-sidebar-border">
                            <th class="px-4 py-2 font-medium">Name</th>
                            <th class="px-4 py-2 font-medium">Email</th>
                            <th class="px-4 py-2 font-medium">Admin</th>
                            <th class="px-4 py-2 text-right font-medium">Watchlist</th>
                            <th class="px-4 py-2 text-right font-medium">Rules</th>
                            <th class="px-4 py-2 font-medium">Joined</th>
                            <th class="px-4 py-2 text-right font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sidebar-border/70 dark:divide-sidebar-border">
                        <tr v-for="user in users.data" :key="user.id" class="hover:bg-accent">
                            <td class="px-4 py-2 font-medium text-foreground">{{ user.name }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ user.email }}</td>
                            <td class="px-4 py-2">
                                <span
                                    v-if="user.is_admin"
                                    class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"
                                >
                                    <ShieldCheck class="size-3" />
                                    Admin
                                </span>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-muted-foreground">{{ user.watchlist_count }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-muted-foreground">{{ user.rules_count }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ user.created_at ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">
                                <Button variant="outline" size="sm" @click="toggleAdmin(user.id)">
                                    {{ user.is_admin ? 'Revoke admin' : 'Make admin' }}
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="users.data.length === 0">
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-muted-foreground">No users found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex items-center justify-between text-sm text-muted-foreground">
            <span>Page {{ users.current_page }} of {{ users.last_page }}</span>
            <div class="flex items-center gap-2">
                <Link
                    v-if="users.prev_page_url"
                    :href="users.prev_page_url"
                    preserve-scroll
                    class="rounded-md border border-sidebar-border/70 px-3 py-1.5 font-medium text-foreground transition-colors hover:bg-accent dark:border-sidebar-border"
                >
                    Newer
                </Link>
                <Link
                    v-if="users.next_page_url"
                    :href="users.next_page_url"
                    preserve-scroll
                    class="rounded-md border border-sidebar-border/70 px-3 py-1.5 font-medium text-foreground transition-colors hover:bg-accent dark:border-sidebar-border"
                >
                    Older
                </Link>
            </div>
        </div>
    </div>
</template>
