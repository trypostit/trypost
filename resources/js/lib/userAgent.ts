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
