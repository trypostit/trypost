export const NodeType = {
    Trigger: 'trigger',
    Generate: 'generate',
    Delay: 'delay',
    Condition: 'condition',
    Publish: 'publish',
    End: 'end',
    FetchRss: 'fetch_rss',
    HttpRequest: 'http_request',
} as const;

export type NodeTypeValue = (typeof NodeType)[keyof typeof NodeType];
