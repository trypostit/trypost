import {
    computed,
    inject,
    provide,
    readonly,
    ref,
    watch,
    type ComputedRef,
    type InjectionKey,
    type Ref,
} from 'vue';

import type { InstagramAudio } from '@/types/instagram';

/** What a reel preview tells its video about the soundtrack picked in the composer. */
interface PreviewSoundtrack {
    playing: ComputedRef<boolean>;
    videoVolume: ComputedRef<number>;
    start: () => void;
    stop: () => void;
}

const PREVIEW_SOUNDTRACK: InjectionKey<PreviewSoundtrack> =
    Symbol('previewSoundtrack');

const playingAudioId = ref<string | null>(null);
let player: HTMLAudioElement | null = null;

const stop = (): void => {
    player?.pause();
    player = null;
    playingAudioId.value = null;
};

const play = (
    audioId: string,
    url: string,
    volume: number,
    onUnavailable?: () => void,
): void => {
    stop();
    const audio = new Audio(url);
    audio.volume = volume / 100;
    audio.addEventListener('ended', stop);
    player = audio;
    playingAudioId.value = audioId;
    void audio.play().catch((error: unknown) => {
        if (player === audio) stop();
        if ((error as DOMException)?.name !== 'AbortError') onUnavailable?.();
    });
};

const setVolume = (volume: number): void => {
    if (player) player.volume = volume / 100;
};

/**
 * The one Instagram audio preview playing in the composer. Playing the reel
 * preview's video plays the post's own track along with it.
 */
export const useReelSoundtrack = () => ({
    playingAudioId: readonly(playingAudioId),
    play,
    stop,
    setVolume,
});

/** The picked track as the reel preview names it: title and artist. */
export const soundtrackLabel = (
    audio: InstagramAudio | null | undefined,
): string | null =>
    audio?.id
        ? [audio.title, audio.artist].filter(Boolean).join(' · ') || null
        : null;

/**
 * Ties the reel preview's video to the post's picked track: playing the video
 * plays the track with it (see `useSoundtrackVideo`).
 */
export const providePreviewSoundtrack = (
    audio: () => InstagramAudio | null,
): void => {
    provide(PREVIEW_SOUNDTRACK, {
        playing: computed(
            () =>
                Boolean(audio()?.id) && playingAudioId.value === audio()?.id,
        ),
        videoVolume: computed(() => (audio()?.video_volume ?? 100) / 100),
        start: () => {
            const track = audio();
            if (track?.id && track.preview_url) {
                play(track.id, track.preview_url, track.audio_volume ?? 100);
            }
        },
        stop,
    });
};

/**
 * Plays the soundtrack its reel provides when a preview video plays: the
 * video at the chosen video volume, the track at the chosen music volume.
 * Pausing or ending the video stops the track, and stopping the track pauses
 * the video. Playing the track alone (the settings card) leaves the video
 * still. Outside a reel preview it does nothing.
 *
 * @returns what to call when the video plays and when it pauses
 */
export const useSoundtrackVideo = (
    video: Ref<HTMLVideoElement | null>,
): { onVideoPlay: () => void; onVideoPause: () => void } => {
    const soundtrack = inject(PREVIEW_SOUNDTRACK, null);

    watch(
        () => soundtrack?.playing.value ?? false,
        (playing) => {
            if (!playing) video.value?.pause();
        },
    );

    watch(
        () => soundtrack?.videoVolume.value,
        (volume) => {
            if (video.value && volume !== undefined) {
                video.value.volume = volume;
            }
        },
    );

    return {
        onVideoPlay: (): void => {
            if (!soundtrack) return;
            if (video.value) video.value.volume = soundtrack.videoVolume.value;
            if (!soundtrack.playing.value) soundtrack.start();
        },
        onVideoPause: (): void => {
            if (soundtrack?.playing.value) soundtrack.stop();
        },
    };
};
