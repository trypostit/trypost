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
