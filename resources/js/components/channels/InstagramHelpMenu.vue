<script setup lang="ts">
import ConnectHelpMenu, {
    type ConnectHelpLink,
} from '@/components/channels/ConnectHelpMenu.vue';

const HELP_LINKS: readonly ConnectHelpLink[] = [
    {
        label: 'accounts.instagram_connect.help.account_type',
        testId: 'instagram-connect-help-account-type',
        href: 'https://help.instagram.com/138925576505882',
    },
    {
        label: 'accounts.instagram_connect.help.convert',
        testId: 'instagram-connect-help-convert',
        href: 'https://help.instagram.com/502981923235522',
    },
];
</script>

<template>
    <ConnectHelpMenu :links="HELP_LINKS" test-id="instagram-connect-help" />
</template>
