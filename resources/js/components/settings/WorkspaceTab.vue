<script setup lang="ts">
import { Form } from '@inertiajs/vue3';

import WorkspaceController from '@/actions/App/Http/Controllers/App/WorkspaceController';
import DeleteWorkspace from '@/components/settings/DeleteWorkspace.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import PhotoUpload from '@/components/PhotoUpload.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { useWorkspaceRole } from '@/composables/useWorkspaceRole';
import { uploadLogo, deleteLogo } from '@/routes/app/workspace';

interface Workspace {
    id: string;
    name: string;
    has_logo: boolean;
    logo_url: string | null;
    timezone?: string | null;
    datetime_format?: string | null;
}

defineProps<{
    workspace: Workspace;
    isOnlyWorkspace: boolean;
    otherMemberCount: number;
}>();

const { canManageBilling } = useWorkspaceRole();

const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

const timezones: string[] = (() => {
    try {
        return (Intl as any).supportedValuesOf('timeZone') as string[];
    } catch {
        return [browserTimezone];
    }
})();

const formatPresets = [
    { value: 'dmy_24', example: '20 Aug 2026, 18:30' },
    { value: 'dmy_12', example: '20 Aug 2026, 6:30 PM' },
    { value: 'dots_24', example: '20.08.2026 18:30' },
    { value: 'slash_12', example: '08/20/2026 6:30 PM' },
];
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

                <div class="grid gap-2">
                    <Label for="timezone">{{ $t('settings.workspace.timezone') }}</Label>
                    <select
                        id="timezone"
                        name="timezone"
                        class="border-input h-9 w-full rounded-md border-2 bg-transparent px-3 py-1 text-sm shadow-2xs"
                    >
                        <option value="" :selected="!workspace.timezone">{{ $t('settings.workspace.timezone_auto', { tz: browserTimezone }) }}</option>
                        <option v-for="tz in timezones" :key="tz" :value="tz" :selected="workspace.timezone === tz">{{ tz }}</option>
                    </select>
                    <p class="text-xs text-foreground/60">{{ $t('settings.workspace.timezone_description') }}</p>
                    <InputError :message="errors.timezone" />
                </div>

                <div class="grid gap-2">
                    <Label for="datetime_format">{{ $t('settings.workspace.datetime_format') }}</Label>
                    <select
                        id="datetime_format"
                        name="datetime_format"
                        class="border-input h-9 w-full rounded-md border-2 bg-transparent px-3 py-1 text-sm shadow-2xs"
                    >
                        <option value="" :selected="!workspace.datetime_format">{{ $t('settings.workspace.datetime_format_auto') }}</option>
                        <option v-for="preset in formatPresets" :key="preset.value" :value="preset.value" :selected="workspace.datetime_format === preset.value">{{ preset.example }}</option>
                    </select>
                    <p class="text-xs text-foreground/60">{{ $t('settings.workspace.datetime_format_description') }}</p>
                    <InputError :message="errors.datetime_format" />
                </div>

                <Button :disabled="processing">{{ $t('settings.workspace.save') }}</Button>
            </Form>
        </div>

        <template v-if="canManageBilling">
            <Separator />

            <DeleteWorkspace
                :workspace="workspace"
                :is-only-workspace="isOnlyWorkspace"
                :other-member-count="otherMemberCount"
            />
        </template>
    </div>
</template>
