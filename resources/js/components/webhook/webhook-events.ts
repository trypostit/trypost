import { trans } from 'laravel-vue-i18n';

export const webhookEventGroups = [
    {
        labelKey: 'webhooks.events.group_posts',
        events: [
            'post.created',
            'post.scheduled',
            'post.unscheduled',
            'post.published',
            'post.partially_published',
            'post.failed',
            'post.deleted',
        ],
    },
];

export const allWebhookEvents = webhookEventGroups.flatMap(
    (group) => group.events,
);

export const webhookEventLabelKey = (event: string): string =>
    `webhooks.events.${event.replaceAll('.', '_')}`;

export const webhookEventDescriptionKey = (event: string): string =>
    `webhooks.event_descriptions.${event.replaceAll('.', '_')}`;

export const webhookEventLabel = (event: string): string => trans(webhookEventLabelKey(event));
