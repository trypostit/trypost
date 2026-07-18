<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { IconCopy, IconDots, IconKey, IconTrash } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import ApiKeyController from '@/actions/App/Http/Controllers/App/ApiKeyController';
import CreateApiKeyDialog from '@/components/api-keys/CreateApiKeyDialog.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import PageHeader from '@/components/PageHeader.vue';
import SettingsTabsNav from '@/components/settings/SettingsTabsNav.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { copyToClipboard } from '@/lib/utils';
import { members as membersRoute } from '@/routes/app';
import { index as apiKeysRoute } from '@/routes/app/api-keys';
import { brand as brandRoute, settings as workspaceSettings } from '@/routes/app/workspace';
interface ApiToken {
    id: string;
    name: string;
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string;
}

interface Props {
    apiTokens: ApiToken[];
}

defineProps<Props>();

const page = usePage();
const newToken = computed(() => (page.props.flash as Record<string, unknown>)?.plainToken as string | undefined);

const createDialogOpen = ref(false);
const confirmDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const tabs = computed(() => [
    { name: 'workspace', label: trans('settings.workspace.tabs.workspace'), href: workspaceSettings.url() },
    { name: 'brand', label: trans('settings.workspace.tabs.brand'), href: brandRoute.url() },
    { name: 'members', label: trans('settings.workspace.tabs.users'), href: membersRoute.url() },
    { name: 'api-keys', label: trans('settings.workspace.tabs.api_keys'), href: apiKeysRoute.url() },
]);
</script>

<template>
    <Head :title="$t('settings.api_keys.page_title')" />

    <AppLayout>
        <div class="mx-auto max-w-4xl space-y-8 px-6 py-8">
            <PageHeader
                :title="$t('settings.hub.title')"
                :description="$t('settings.hub.description')"
                />

            <SettingsTabsNav :tabs="tabs" active="api-keys" />

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <HeadingSmall
                    :title="$t('settings.api_keys.heading')"
                    :description="$t('settings.api_keys.description')"
                />
                <Button @click="createDialogOpen = true">
                    {{ $t('settings.api_keys.create') }}
                </Button>
            </div>

            <!-- New token alert -->
            <div
                v-if="newToken"
                class="rounded-xl border-2 border-foreground bg-emerald-50 p-4 shadow-2xs"
            >
                <p class="mb-2 text-sm font-bold text-emerald-800">
                    {{ $t('settings.api_keys.new_token_message') }}
                </p>
                <div class="flex items-stretch gap-2">
                    <code class="flex h-9 min-w-0 flex-1 items-center rounded-md border-2 border-foreground bg-card px-3 font-mono text-sm font-bold text-foreground shadow-2xs">
                        <span class="block truncate">{{ newToken }}</span>
                    </code>
                    <Button
                        variant="outline"
                        class="shrink-0"
                        @click="copyToClipboard(newToken!, trans('settings.api_keys.copy_success'))"
                    >
                        <IconCopy class="size-4" />
                        {{ $t('settings.api_keys.copy') }}
                    </Button>
                </div>
            </div>

            <div v-if="apiTokens.length > 0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ $t('settings.api_keys.table.name') }}</TableHead>
                            <TableHead>{{ $t('settings.api_keys.table.expires') }}</TableHead>
                            <TableHead>{{ $t('settings.api_keys.table.last_used') }}</TableHead>
                            <TableHead class="w-10" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="token in apiTokens" :key="token.id">
                            <TableCell>{{ token.name }}</TableCell>
                            <TableCell>
                                {{ token.expires_at ? date.formatDate(token.expires_at) : $t('settings.api_keys.table.never') }}
                            </TableCell>
                            <TableCell>
                                {{ token.last_used_at ? date.diffForHumans(token.last_used_at) : $t('settings.api_keys.table.never') }}
                            </TableCell>
                            <TableCell>
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button variant="outline" size="icon" class="size-9">
                                            <IconDots class="size-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            @click="copyToClipboard(token.id, trans('settings.api_keys.actions.copy_id_success'))"
                                        >
                                            <IconCopy class="size-4" />
                                            {{ $t('settings.api_keys.actions.copy_id') }}
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            variant="destructive"
                                            @click="confirmDeleteModal?.open({ url: ApiKeyController.destroy.url(token.id), confirmText: token.name })"
                                        >
                                            <IconTrash class="size-4" />
                                            {{ $t('settings.api_keys.actions.delete') }}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <EmptyState
                v-else-if="!newToken"
                :icon="IconKey"
                :title="$t('settings.api_keys.empty.title')"
                :description="$t('settings.api_keys.empty.description')"
            />
        </div>
    </AppLayout>

    <CreateApiKeyDialog v-model:open="createDialogOpen" />

    <ConfirmDeleteModal
        ref="confirmDeleteModal"
        :title="$t('settings.api_keys.delete_modal.title')"
        :description="$t('settings.api_keys.delete_modal.description')"
        :action="$t('settings.api_keys.delete_modal.action')"
    />
</template>
