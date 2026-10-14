/**
 * Best-effort browser/OS detection from a user-agent string.
 *
 * Order matters: more specific identifiers (Edge, Opera, Samsung, etc.) must
 * be checked before the engines they piggyback on (Chrome, Safari).
 */

export const parseBrowserName = (userAgent: string | null): string => {
    if (!userAgent) {
        return 'Unknown browser';
    }

    if (
        userAgent.includes('Edg/') ||
        userAgent.includes('EdgiOS/') ||
        userAgent.includes('EdgA/')
    ) {
        return 'Edge';
    }

    if (userAgent.includes('OPR/') || userAgent.includes('Opera/')) {
        return 'Opera';
    }

    if (userAgent.includes('Vivaldi/')) {
        return 'Vivaldi';
    }

    if (userAgent.includes('SamsungBrowser/')) {
        return 'Samsung Internet';
    }

    if (userAgent.includes('UCBrowser/')) {
        return 'UC Browser';
    }

    if (userAgent.includes('YaBrowser/')) {
        return 'Yandex';
    }

    if (userAgent.includes('DuckDuckGo/') || userAgent.includes('Ddg/')) {
        return 'DuckDuckGo';
    }

    if (userAgent.includes('HuaweiBrowser/')) {
        return 'Huawei Browser';
    }

    if (userAgent.includes('VivoBrowser/')) {
        return 'Vivo Browser';
    }

    if (userAgent.includes('QQBrowser/')) {
        return 'QQ Browser';
    }

    if (userAgent.includes('Ecosia')) {
        return 'Ecosia';
    }

    if (userAgent.startsWith('curl/')) {
        return 'curl';
    }

    if (userAgent.includes('FxiOS/') || userAgent.includes('Firefox/')) {
        return 'Firefox';
    }

    if (userAgent.includes('Chromium/')) {
        return 'Chromium';
    }

    if (userAgent.includes('CriOS/') || userAgent.includes('Chrome/')) {
        return 'Chrome';
    }

    if (userAgent.includes('Safari/')) {
        return 'Safari';
    }

    if (userAgent.includes('Trident/') || userAgent.includes('MSIE ')) {
        return 'Internet Explorer';
    }

    return 'Unknown browser';
};

export const parseOsName = (userAgent: string | null): string | null => {
    if (!userAgent) {
        return null;
    }

    if (/PlayStation/.test(userAgent)) {
        return 'PlayStation';
    }

    if (/KAIOS/i.test(userAgent)) {
        return 'KaiOS';
    }

    if (/Tizen/.test(userAgent)) {
        return 'Tizen';
    }

    if (/HarmonyOS|OpenHarmony/.test(userAgent)) {
        return 'HarmonyOS';
    }

    if (/Silk\/|Kindle|KF[A-Z]{2,4}\b/.test(userAgent)) {
        return 'Fire OS';
    }

    if (/iPad/.test(userAgent)) {
        return 'iPadOS';
    }

    if (/iPhone|iPod/.test(userAgent)) {
        return 'iOS';
    }

    if (/Android/.test(userAgent)) {
        return 'Android';
    }

    if (/Windows Phone/.test(userAgent)) {
        return 'Windows Phone';
    }

    if (/Windows NT/.test(userAgent)) {
        return 'Windows';
    }

    if (/Mac OS X|Macintosh/.test(userAgent)) {
        return 'macOS';
    }

    if (/CrOS/.test(userAgent)) {
        return 'ChromeOS';
    }

    if (/FreeBSD|OpenBSD|NetBSD/.test(userAgent)) {
        return 'BSD';
    }

    if (/Ubuntu/.test(userAgent)) {
        return 'Ubuntu';
    }

    if (/Fedora/.test(userAgent)) {
        return 'Fedora';
    }

    if (/Linux/.test(userAgent)) {
        return 'Linux';
    }

    return null;
};

const BROWSER_ICONS: Record<string, string> = {
    Chrome: 'chrome.svg',
    Chromium: 'chromium.svg',
    curl: 'curl.svg',
    DuckDuckGo: 'duckduckgo.svg',
    Ecosia: 'ecosia.png',
    Edge: 'edge.svg',
    Firefox: 'firefox.svg',
    'Huawei Browser': 'huawei.png',
    Opera: 'opera.svg',
    'QQ Browser': 'qq.png',
    Safari: 'safari.png',
    'Samsung Internet': 'samsung-internet.svg',
    'UC Browser': 'uc.svg',
    Vivaldi: 'vivaldi.svg',
    'Vivo Browser': 'vivo.png',
    Yandex: 'yandex.png',
};

const OS_ICONS: Record<string, string> = {
    Android: 'android.png',
    BSD: 'freebsd.png',
    ChromeOS: 'chrome_os.png',
    Fedora: 'fedora.png',
    'Fire OS': 'fire_os.png',
    HarmonyOS: 'harmony_os.png',
    iOS: 'ios.png',
    iPadOS: 'ipad_os.png',
    KaiOS: 'kai_os.png',
    Linux: 'gnu_linux.png',
    macOS: 'mac.png',
    PlayStation: 'playstation.png',
    Tizen: 'tizen.png',
    Ubuntu: 'ubuntu.png',
    Windows: 'windows.png',
    'Windows Phone': 'windows.png',
};

export const browserIconUrl = (userAgent: string | null): string =>
    `/images/browsers/${BROWSER_ICONS[parseBrowserName(userAgent)] ?? 'fallback.svg'}`;

const MONOCHROME_OS = new Set(['macOS', 'iPadOS', 'PlayStation']);

export const osIconUrl = (userAgent: string | null): string => {
    const os = parseOsName(userAgent);

    return `/images/os/${(os && OS_ICONS[os]) || 'fallback.svg'}`;
};

/**
 * Black, single-colour logos that would vanish on the dark theme.
 */
export const isMonochromeOsIcon = (userAgent: string | null): boolean =>
    MONOCHROME_OS.has(parseOsName(userAgent) ?? '');

export type DeviceType = 'desktop' | 'mobile' | 'tablet';

export const parseDeviceType = (userAgent: string | null): DeviceType => {
    if (!userAgent) {
        return 'desktop';
    }

    if (
        /iPad|Tablet|PlayBook|Silk\/|KF[A-Z]{2,4}\b/.test(userAgent) ||
        (/Android/.test(userAgent) && !/Mobile/.test(userAgent))
    ) {
        return 'tablet';
    }

    if (/Mobile|iPhone|iPod|Android|Windows Phone|KAIOS/i.test(userAgent)) {
        return 'mobile';
    }

    return 'desktop';
};

export const deviceIconUrl = (userAgent: string | null): string =>
    `/images/devices/${parseDeviceType(userAgent)}.png`;

const BROWSER_VERSION_PATTERNS: Record<string, RegExp> = {
    Chrome: /(?:Chrome|CriOS)\/(\d+(?:\.\d+)*)/,
    Chromium: /Chromium\/(\d+(?:\.\d+)*)/,
    curl: /curl\/(\d+(?:\.\d+)*)/,
    DuckDuckGo: /(?:DuckDuckGo|Ddg)\/(\d+(?:\.\d+)*)/,
    Edge: /Edg(?:A|iOS)?\/(\d+(?:\.\d+)*)/,
    Firefox: /(?:Firefox|FxiOS)\/(\d+(?:\.\d+)*)/,
    'Huawei Browser': /HuaweiBrowser\/(\d+(?:\.\d+)*)/,
    'Internet Explorer': /(?:MSIE |rv:)(\d+(?:\.\d+)*)/,
    Opera: /(?:OPR|Opera)\/(\d+(?:\.\d+)*)/,
    'QQ Browser': /QQBrowser\/(\d+(?:\.\d+)*)/,
    Safari: /Version\/(\d+(?:\.\d+)*)/,
    'Samsung Internet': /SamsungBrowser\/(\d+(?:\.\d+)*)/,
    'UC Browser': /UCBrowser\/(\d+(?:\.\d+)*)/,
    Vivaldi: /Vivaldi\/(\d+(?:\.\d+)*)/,
    'Vivo Browser': /VivoBrowser\/(\d+(?:\.\d+)*)/,
    Yandex: /YaBrowser\/(\d+(?:\.\d+)*)/,
};

/**
 * The browser's full version exactly as its user agent reports it (Chrome
 * freezes everything after the major number to zeros), kept whole for
 * debugging.
 */
export const parseBrowserVersion = (userAgent: string | null): string | null => {
    const pattern = BROWSER_VERSION_PATTERNS[parseBrowserName(userAgent)];

    return (userAgent && pattern?.exec(userAgent)?.[1]) || null;
};

const WINDOWS_VERSIONS: Record<string, string> = {
    '6.1': '7',
    '6.2': '8',
    '6.3': '8.1',
};

/**
 * The system version as the user agent reports it. Windows 10 and 11 both
 * report "NT 10.0", so no version is shown for them.
 */
export const parseOsVersion = (userAgent: string | null): string | null => {
    if (!userAgent) {
        return null;
    }

    const read = (pattern: RegExp): string | null =>
        pattern.exec(userAgent)?.[1]?.replace(/_/g, '.') ?? null;

    switch (parseOsName(userAgent)) {
        case 'macOS':
            return read(/Mac OS X (\d+(?:[._]\d+)*)/);
        case 'iOS':
        case 'iPadOS':
            return read(/OS (\d+(?:[._]\d+)*)/);
        case 'Android':
            return read(/Android (\d+(?:\.\d+)*)/);
        case 'Windows':
            return WINDOWS_VERSIONS[read(/Windows NT (\d+\.\d+)/) ?? ''] ?? null;
        case 'KaiOS':
            return read(/KAIOS\/(\d+(?:\.\d+)*)/i);
        default:
            return null;
    }
};
