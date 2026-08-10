<script setup lang="ts">
import { IconChevronDown } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
    ComboboxTrigger,
} from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import AuthorizeLayout from '@/layouts/mcp/AuthorizeLayout.vue';
import { approve, deny } from '@/routes/passport/authorizations';

type Scope = {
    id: string;
    description: string;
};

type WorkspaceOption = {
    id: string;
    name: string;
};

const props = defineProps<{
    client: {
        id: string;
        name: string;
    };
    user: {
        email: string;
    };
    workspaces: WorkspaceOption[];
    selectedWorkspaceId: string;
    scopes: Scope[];
    authToken: string;
    state: string;
}>();

const selectedWorkspace = ref<WorkspaceOption | undefined>(
    props.workspaces.find(
        (workspace) => workspace.id === props.selectedWorkspaceId,
    ) ?? props.workspaces[0],
);

const workspaceIdForSubmit = computed(
    () => selectedWorkspace.value?.id ?? '',
);

const canApprove = computed(() => workspaceIdForSubmit.value !== '');

const approving = ref(false);
const csrfToken =
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';

const pageTitle = computed(() => trans('mcp.authorize.page_title'));

const heading = computed(() =>
    trans('mcp.authorize.heading', {
        client: props.client.name,
    }),
);

const description = computed(
    () =>
        `${trans('mcp.authorize.intro')} ${trans('mcp.authorize.intro_capability')}`,
);

const scopeLabel = (scope: Scope): string =>
    scope.id === 'mcp:use'
        ? trans('mcp.authorize.scope_mcp_use')
        : scope.description;

const onApproveSubmit = (event: Event): void => {
    if (! canApprove.value) {
        event.preventDefault();

        return;
    }

    approving.value = true;

    // Popup MCP clients expect the window to close after the redirect.
    window.setTimeout(() => {
        const checkRedirect = window.setInterval(() => {
            if (
                !window.location.href.includes('/oauth/authorize') ||
                window.location.search.includes('code=') ||
                window.location.search.includes('error=')
            ) {
                window.clearInterval(checkRedirect);
                window.close();
            }
        }, 100);

        window.setTimeout(() => {
            window.clearInterval(checkRedirect);
            window.close();
        }, 5000);
    }, 200);
};

const onDenySubmit = (): void => {
    window.setTimeout(() => {
        window.close();
    }, 200);
};
</script>

<template>
    <AuthorizeLayout
        :page-title="pageTitle"
        :title="heading"
        :description="description"
    >
        <div class="space-y-4">
            <div class="space-y-1.5">
                <p class="text-sm text-muted-foreground">
                    {{ $t('mcp.authorize.logged_in_as') }}
                </p>
                <p class="font-medium" dusk="mcp-authorize-email">
                    {{ user.email }}
                </p>
            </div>

            <div class="space-y-1.5">
                <Label>{{ $t('mcp.authorize.workspace') }}</Label>
                <Combobox
                    v-model="selectedWorkspace"
                    by="id"
                    :display-value="
                        (workspace: WorkspaceOption | undefined) =>
                            workspace?.name ?? ''
                    "
                >
                    <ComboboxAnchor class="w-full">
                        <ComboboxTrigger as-child>
                            <button
                                type="button"
                                class="flex h-10 w-full items-center justify-between rounded-md border-2 border-foreground bg-card px-3 py-2 text-sm font-medium text-foreground shadow-2xs transition-colors hover:bg-muted/40"
                                dusk="mcp-authorize-workspace"
                            >
                                <span
                                    :class="
                                        selectedWorkspace
                                            ? 'text-foreground'
                                            : 'text-foreground/50'
                                    "
                                >
                                    {{
                                        selectedWorkspace?.name ??
                                        $t('mcp.authorize.select_workspace')
                                    }}
                                </span>
                                <IconChevronDown
                                    class="size-4 shrink-0 text-foreground/60"
                                />
                            </button>
                        </ComboboxTrigger>
                    </ComboboxAnchor>
                    <ComboboxList>
                        <ComboboxInput
                            :placeholder="
                                $t('mcp.authorize.search_workspace')
                            "
                        />
                        <ComboboxEmpty>
                            {{ $t('mcp.authorize.no_workspace_found') }}
                        </ComboboxEmpty>
                        <ComboboxGroup>
                            <ComboboxItem
                                v-for="workspace in workspaces"
                                :key="workspace.id"
                                :value="workspace"
                            >
                                {{ workspace.name }}
                            </ComboboxItem>
                        </ComboboxGroup>
                    </ComboboxList>
                </Combobox>
                <p class="text-xs text-muted-foreground">
                    {{ $t('mcp.authorize.workspace_scope') }}
                </p>
            </div>
        </div>

        <div v-if="scopes.length > 0" class="space-y-2">
            <p class="text-sm font-medium">
                {{ $t('mcp.authorize.permissions') }}
            </p>
            <ul class="space-y-2">
                <li
                    v-for="scope in scopes"
                    :key="scope.id"
                    class="flex items-start gap-2"
                >
                    <div class="mt-0.5 rounded-full bg-primary/10 p-1">
                        <div class="size-1.5 rounded-full bg-primary" />
                    </div>
                    <span class="text-sm text-muted-foreground">
                        {{ scopeLabel(scope) }}
                    </span>
                </li>
            </ul>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:justify-start">
            <!-- Native forms: Passport redirects off-site; Inertia visits would break the OAuth popup. -->
            <form
                id="authorizeForm"
                method="POST"
                :action="approve.url()"
                class="flex-1"
                @submit="onApproveSubmit"
            >
                <input type="hidden" name="_token" :value="csrfToken" />
                <input type="hidden" name="state" :value="state" />
                <input type="hidden" name="client_id" :value="client.id" />
                <input type="hidden" name="auth_token" :value="authToken" />
                <input
                    type="hidden"
                    name="workspace_id"
                    :value="workspaceIdForSubmit"
                />
                <Button
                    type="submit"
                    class="w-full"
                    :class="{
                        'pointer-events-none opacity-25': !canApprove,
                    }"
                    :loading="approving"
                    dusk="mcp-authorize-approve"
                >
                    {{ $t('mcp.authorize.approve') }}
                </Button>
            </form>

            <form
                method="POST"
                :action="deny.url()"
                class="flex-1"
                @submit="onDenySubmit"
            >
                <input type="hidden" name="_token" :value="csrfToken" />
                <input type="hidden" name="_method" value="DELETE" />
                <input type="hidden" name="state" :value="state" />
                <input type="hidden" name="client_id" :value="client.id" />
                <input type="hidden" name="auth_token" :value="authToken" />
                <Button
                    type="submit"
                    variant="outline"
                    class="w-full"
                    dusk="mcp-authorize-cancel"
                >
                    {{ $t('mcp.authorize.cancel') }}
                </Button>
            </form>
        </div>
    </AuthorizeLayout>
</template>
