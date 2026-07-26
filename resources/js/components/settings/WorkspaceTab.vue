<script setup lang="ts">
import { Form, Link, usePage } from '@inertiajs/vue3';
import { IconTrash } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import WorkspaceController from '@/actions/App/Http/Controllers/App/WorkspaceController';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import PhotoUpload from '@/components/PhotoUpload.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { useWorkspaceRole } from '@/composables/useWorkspaceRole';
import { index as billingIndex } from '@/routes/app/billing';
import { destroy as destroyWorkspace } from '@/routes/app/workspaces';
import { uploadLogo, deleteLogo } from '@/routes/app/workspace';

interface Workspace {
    id: string;
    name: string;
    has_logo: boolean;
    logo_url: string | null;
}

const props = defineProps<{
    workspace: Workspace;
    canDelete: boolean;
    isOnlyWorkspace: boolean;
}>();

const page = usePage();
const isSelfHosted = computed(() => Boolean(page.props.selfHosted));
const { canManageBilling } = useWorkspaceRole();

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const openDeleteModal = () => {
    deleteModal.value?.open({
        url: destroyWorkspace.url(props.workspace.id),
        confirmText: props.workspace.name,
    });
};
</script>

<template>
    <div class="space-y-12">
        <div class="flex flex-col space-y-6">
            <HeadingSmall
                :title="$t('settings.workspace.logo_heading')"
                :description="$t('settings.workspace.logo_description')"
            />

            <PhotoUpload
                :photo-url="workspace.logo_url"
                :has-photo="workspace.has_logo"
                :name="workspace.name"
                :upload-url="uploadLogo().url"
                :delete-url="deleteLogo().url"
            />
        </div>

        <Separator />

        <div class="flex flex-col space-y-6">
            <HeadingSmall
                :title="$t('settings.workspace.heading')"
                :description="$t('settings.workspace.description')"
            />

            <Form
                v-bind="WorkspaceController.updateSettings.form()"
                v-slot="{ errors, processing }"
                class="space-y-6"
            >
                <div class="grid gap-2">
                    <Label for="name">{{ $t('settings.workspace.name') }}</Label>
                    <Input
                        id="name"
                        name="name"
                        :default-value="workspace.name"
                        :placeholder="$t('settings.workspace.name_placeholder')"
                    />
                    <InputError :message="errors.name" />
                </div>

                <Button :disabled="processing">{{ $t('settings.workspace.save') }}</Button>
            </Form>
        </div>

        <Separator v-if="canDelete" />

        <section v-if="canDelete" class="space-y-4" dusk="workspace-danger-zone">
            <HeadingSmall
                :title="$t('settings.workspace.danger_title')"
                :description="$t('settings.workspace.danger_description')"
            />

            <div
                class="flex items-center justify-between gap-4 rounded-xl border-2 border-destructive/30 bg-destructive/5 p-4"
            >
                <div class="text-sm">
                    <p class="font-medium">
                        {{ $t('settings.workspace.delete_title') }}
                    </p>
                    <p class="text-foreground/60">
                        <template v-if="isOnlyWorkspace">
                            {{ $t('settings.workspace.delete_only_description') }}
                        </template>
                        <template v-else-if="isSelfHosted">
                            {{ $t('settings.workspace.delete_description_self_hosted') }}
                        </template>
                        <template v-else>
                            {{ $t('settings.workspace.delete_description') }}
                        </template>
                    </p>
                </div>
                <Button
                    v-if="!isOnlyWorkspace"
                    variant="destructive"
                    dusk="workspace-delete"
                    @click="openDeleteModal"
                >
                    <IconTrash class="size-4" />
                    {{ $t('settings.workspace.delete_action') }}
                </Button>
                <Button
                    v-else-if="canManageBilling"
                    variant="outline"
                    as-child
                    dusk="workspace-delete-billing-link"
                >
                    <Link :href="billingIndex()">
                        {{ $t('settings.workspace.delete_go_to_billing') }}
                    </Link>
                </Button>
            </div>
        </section>

        <ConfirmDeleteModal
            ref="deleteModal"
            :title="$t('settings.workspace.delete_confirm_title')"
            :description="
                isSelfHosted
                    ? $t('settings.workspace.delete_confirm_description_self_hosted')
                    : $t('settings.workspace.delete_confirm_description')
            "
            :action="$t('settings.workspace.delete_action')"
            :cancel="$t('settings.workspace.delete_cancel')"
        />
    </div>
</template>
