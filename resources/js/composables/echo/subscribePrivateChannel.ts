import { echo } from '@laravel/echo-vue';

/**
 * Subscribe to a private channel, let the caller attach listeners via
 * `configure`, and resolve once we know if the subscription is live — await
 * this before triggering whatever broadcasts onto the channel, otherwise
 * events sent before the handshake completes are lost with no replay.
 *
 * Resolves `true` once confirmed (or on timeout — ambiguous, so we proceed
 * optimistically), `false` only on a definitive subscribe error.
 */
export const subscribePrivateChannel = (
    channelName: string,
    configure: (channel: ReturnType<ReturnType<typeof echo>['private']>) => void,
    timeoutMs = 5000,
): Promise<boolean> => {
    const channel = echo().private(channelName);
    configure(channel);

    return new Promise((resolve) => {
        // resolve() no-ops after the first call, so whichever fires first wins.
        setTimeout(() => resolve(true), timeoutMs);
        channel.subscribed(() => resolve(true)).error(() => resolve(false));
    });
};
