import { echo } from '@laravel/echo-vue';

/** Resolves once subscribed (or on timeout), false only on a definitive subscribe error. */
export const subscribePrivateChannel = (
    channelName: string,
    configure: (channel: ReturnType<ReturnType<typeof echo>['private']>) => void,
    timeoutMs = 5000,
): Promise<boolean> => {
    const channel = echo().private(channelName);
    configure(channel);

    return new Promise((resolve) => {
        const timeout = setTimeout(() => resolve(true), timeoutMs);
        channel
            .subscribed(() => { clearTimeout(timeout); resolve(true); })
            .error(() => { clearTimeout(timeout); resolve(false); });
    });
};
