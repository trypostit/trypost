import { useEcho } from '@laravel/echo-vue';

export const useWebhookEcho = <T = unknown>(
    webhookId: string,
    event: string | string[],
    callback: (payload: T) => void,
) => {
    return useEcho<T>(`webhook.${webhookId}.logs`, event, callback);
};
