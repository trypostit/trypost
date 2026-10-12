<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { IconCircleCheckFilled, IconCopy } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import WelcomeContinueButton from '@/components/welcome/WelcomeContinueButton.vue';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { mcpClients } from '@/lib/mcpClients';
import { copyToClipboard } from '@/lib/utils';
import { index as postsIndex } from '@/routes/app/posts';

interface ConnectedClient {
    client_id: string;
    name: string;
}

const props = defineProps<{
    prompt: string;
    connectedClients: ConnectedClient[];
}>();

usePoll(2000, { only: ['connectedClients'] });

const connectedClient = computed(
    (): ConnectedClient | null => props.connectedClients[0] ?? null,
);

const copyPrompt = (): void => {
    copyToClipboard(props.prompt, trans('mcp.copied'));
};

const goToSchedule = (): void => {
    router.visit(postsIndex.url());
};
</script>

<template>
    <Head :title="$t('welcome.mcp.title')" />

    <WelcomeLayout
        :title="$t('welcome.mcp.title')"
        :description="$t('welcome.mcp.description')"
    >
        <div class="flex flex-col gap-4">
            <div
                class="overflow-hidden rounded-xl border border-border bg-card"
                data-testid="welcome-mcp-prompt"
            >
                <pre
                    dir="ltr"
                    class="max-h-64 overflow-y-auto p-4 font-mono text-xs leading-5 whitespace-pre-wrap text-foreground"
                    >{{ prompt }}</pre
                >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-t border-border bg-muted px-4 py-3"
                >
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="client in mcpClients"
                            :key="client.id"
                            as="a"
                            :href="client.chatUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            variant="outline"
                            :data-testid="`welcome-mcp-open-${client.id}`"
                        >
                            <img
                                :src="client.logo"
                                alt=""
                                :class="['size-4', client.logoClass]"
                            />
                            {{ $t('welcome.mcp.open', { client: client.label }) }}
                        </Button>
                    </div>
                    <Button
                        type="button"
                        data-testid="welcome-mcp-copy"
                        @click="copyPrompt"
                    >
                        <IconCopy class="size-4" />
                        {{ $t('welcome.mcp.copy_prompt') }}
                    </Button>
                </div>
            </div>

            <p
                class="flex items-center justify-center gap-2 text-sm"
                data-testid="welcome-mcp-status"
                aria-live="polite"
            >
                <template v-if="connectedClient">
                    <IconCircleCheckFilled class="size-4 text-green-500" />
                    <span class="font-medium text-foreground">{{
                        $t('welcome.mcp.connected', {
                            client: connectedClient.name,
                        })
                    }}</span>
                </template>
                <template v-else>
                    <Spinner class="text-muted-foreground" />
                    <span class="text-muted-foreground">{{
                        $t('welcome.mcp.waiting')
                    }}</span>
                </template>
            </p>
        </div>

        <template #actions>
            <WelcomeContinueButton
                v-if="connectedClient"
                :disabled="false"
                testid="welcome-mcp-continue"
                @continue="goToSchedule"
            />
            <Link
                v-else
                :href="postsIndex.url()"
                class="text-sm font-medium text-muted-foreground transition-control hover:text-foreground"
                data-testid="welcome-mcp-skip"
            >
                {{ $t('welcome.mcp.skip') }}
            </Link>
        </template>
    </WelcomeLayout>
</template>
