import { ref } from 'vue';

import { ALLOWED_MIME_TYPES, HEIC_MIME_TYPES } from '@/lib/mediaType';

import { signInWithGoogle } from './googleSignIn';
import { loadScript } from './loadScript';

/** True while the Picker is on screen, so modal dialogs let it be used. */
export const isGooglePickerOpen = ref(false);

/** `api_key`, `app_id` and `picker_sdk` from `Source::publicConfig()`. */
export type GoogleDriveConfig = Record<string, string>;

export interface GoogleDrivePick {
    accessToken: string;
    file: { id: string; name: string };
}

interface PickerDocument {
    [key: string]: unknown;
}

interface PickerResponse {
    [key: string]: unknown;
}

interface DocsView {
    setMimeTypes: (mimeTypes: string) => DocsView;
    setIncludeFolders: (included: boolean) => DocsView;
    setEnableDrives: (enabled: boolean) => DocsView;
}

interface Picker {
    setVisible: (visible: boolean) => void;
    dispose: () => void;
}

interface PickerBuilder {
    setAppId: (appId: string) => PickerBuilder;
    setDeveloperKey: (key: string) => PickerBuilder;
    setOAuthToken: (token: string) => PickerBuilder;
    setLocale: (locale: string) => PickerBuilder;
    addView: (view: DocsView) => PickerBuilder;
    setCallback: (callback: (data: PickerResponse) => void) => PickerBuilder;
    build: () => Picker;
}

interface DriveToken {
    access_token: string;
    expires_in: number;
}

interface GapiNamespace {
    load: (
        libraries: string,
        config: { callback: () => void; onerror: () => void },
    ) => void;
}

declare global {
    interface GoogleNamespace {
        picker?: {
            PickerBuilder: new () => PickerBuilder;
            DocsView: new (viewId?: string) => DocsView;
            ViewId: { DOCS: string };
            Action: { PICKED: string; CANCEL: string };
            Response: { ACTION: string; DOCUMENTS: string };
            Document: { ID: string; NAME: string };
        };
    }

    interface Window {
        gapi?: GapiNamespace;
        google?: GoogleNamespace;
    }
}

const PICKER_LOCALES: Record<string, string> = {
    zh: 'zh-CN',
};

const SIGN_IN_POPUP = { width: 520, height: 640 };

const PICKER_SELECTOR = '.picker-dialog, .picker-dialog-bg';

let sdks: Promise<void> | null = null;

const loadPickerSdk = (config: GoogleDriveConfig): Promise<void> => {
    if (window.google?.picker) return Promise.resolve();

    sdks ??= (async () => {
        if (!window.gapi) {
            await loadScript(config.picker_sdk);
        }

        if (window.google?.picker) return;

        await new Promise<void>((resolve, reject) =>
            window.gapi?.load('picker', {
                callback: resolve,
                onerror: () =>
                    reject(new Error('Failed to load the Google Picker')),
            }),
        );
    })().catch((error: unknown) => {
        sdks = null;

        throw error;
    });

    return sdks;
};

/** Loads the Picker SDK ahead of the click, so it opens right after sign-in. */
export const preloadGoogleDrive = (config: GoogleDriveConfig): void => {
    loadPickerSdk(config).catch(() => undefined);
};

const pickerMimeTypes = (heic: boolean): string =>
    [
        ...Object.values(ALLOWED_MIME_TYPES).flat(),
        ...(heic ? HEIC_MIME_TYPES : []),
    ].join(',');

const insidePicker = (target: EventTarget | null): boolean =>
    target instanceof Element && target.closest(PICKER_SELECTOR) !== null;

/**
 * A modal dialog's focus trap cannot be switched off from outside, so focus
 * moving into or within the Picker never reaches it.
 */
const shieldPickerFocus = (): (() => void) => {
    const shield = (event: Event): void => {
        if (
            insidePicker(event.target) ||
            insidePicker((event as FocusEvent).relatedTarget)
        ) {
            event.stopImmediatePropagation();
        }
    };
    const events = ['focusin', 'focusout'];

    events.forEach((name) => window.addEventListener(name, shield, true));
    isGooglePickerOpen.value = true;

    return () => {
        events.forEach((name) =>
            window.removeEventListener(name, shield, true),
        );
        isGooglePickerOpen.value = false;
    };
};

const openPicker = (
    config: GoogleDriveConfig,
    accessToken: string,
    locale: string,
    heic: boolean,
): Promise<{ id: string; name: string } | null> =>
    new Promise((resolve, reject) => {
        const picker = window.google?.picker;
        if (!picker) {
            resolve(null);

            return;
        }

        const unshield = shieldPickerFocus();

        try {
            const view = new picker.DocsView(picker.ViewId.DOCS)
                .setMimeTypes(pickerMimeTypes(heic))
                .setIncludeFolders(true)
                .setEnableDrives(true);

            const instance = new picker.PickerBuilder()
                .setAppId(config.app_id)
                .setDeveloperKey(config.api_key)
                .setOAuthToken(accessToken)
                .setLocale(PICKER_LOCALES[locale] ?? locale)
                .addView(view)
                .setCallback((data) => {
                    const action = data[picker.Response.ACTION];
                    if (
                        action !== picker.Action.PICKED &&
                        action !== picker.Action.CANCEL
                    ) {
                        return;
                    }

                    unshield();
                    instance.dispose();

                    const documents = data[picker.Response.DOCUMENTS];
                    const picked = Array.isArray(documents)
                        ? (documents[0] as PickerDocument | undefined)
                        : undefined;
                    const id = picked?.[picker.Document.ID];

                    resolve(
                        action === picker.Action.PICKED &&
                            typeof id === 'string'
                            ? {
                                  id,
                                  name: String(
                                      picked?.[picker.Document.NAME] ?? '',
                                  ),
                              }
                            : null,
                    );
                })
                .build();

            instance.setVisible(true);
        } catch (error) {
            unshield();
            reject(error);
        }
    });

/**
 * One file from the user's Drive with the narrow `drive.file` scope: the
 * token only reaches the file picked here. Google's sign-in runs in a popup
 * opened inside the click; the server hands back the short-lived token the
 * Picker needs, which then travels only with the import request. Null when
 * the popup was blocked, sign-in failed (a toast says why) or the user
 * cancels the Picker.
 */
export const pickFromGoogleDrive = async (
    config: GoogleDriveConfig,
    locale: string,
    heic: boolean = false,
    signal?: AbortSignal,
): Promise<GoogleDrivePick | null> => {
    const signIn = signInWithGoogle<DriveToken>(
        'google_drive',
        SIGN_IN_POPUP,
        signal,
    );
    if (!signIn) return null;

    const sdk = loadPickerSdk(config);
    sdk.catch(() => undefined);

    const token = await signIn.result;
    if (!token?.access_token) return null;

    await sdk;

    const file = await openPicker(config, token.access_token, locale, heic);

    return file ? { accessToken: token.access_token, file } : null;
};
