import dayjs from 'dayjs';
import advancedFormat from 'dayjs/plugin/advancedFormat';
import calendar from 'dayjs/plugin/calendar';
import customParseFormat from 'dayjs/plugin/customParseFormat';
import duration from 'dayjs/plugin/duration';
import isBetween from 'dayjs/plugin/isBetween';
import localizedFormat from 'dayjs/plugin/localizedFormat';
import relativeTime from 'dayjs/plugin/relativeTime';
import timezone from 'dayjs/plugin/timezone';
import updateLocale from 'dayjs/plugin/updateLocale';
import utc from 'dayjs/plugin/utc';
import weekday from 'dayjs/plugin/weekday';

// Import locales
import 'dayjs/locale/en';
import 'dayjs/locale/es';
import 'dayjs/locale/pt-br';
import 'dayjs/locale/fr';
import 'dayjs/locale/de';
import 'dayjs/locale/it';
import 'dayjs/locale/nl';
import 'dayjs/locale/pl';
import 'dayjs/locale/el';
import 'dayjs/locale/ja';
import 'dayjs/locale/ko';
import 'dayjs/locale/zh';
import 'dayjs/locale/ru';
import 'dayjs/locale/tr';
import 'dayjs/locale/ar';

// Extend dayjs with plugins
dayjs.extend(utc);
dayjs.extend(timezone);
dayjs.extend(calendar);
dayjs.extend(customParseFormat);
dayjs.extend(relativeTime);
dayjs.extend(duration);
dayjs.extend(updateLocale);
dayjs.extend(advancedFormat);
dayjs.extend(localizedFormat);
dayjs.extend(weekday);
dayjs.extend(isBetween);

// Set Monday as first day of week (to match Carbon/Laravel)
const weekStartMonday = ['en', 'es', 'pt-br', 'fr', 'de', 'it', 'nl', 'pl', 'el', 'ja', 'ko', 'zh', 'ru', 'tr', 'ar'];
weekStartMonday.forEach((locale) => dayjs.updateLocale(locale, { weekStart: 1 }));

export default dayjs;
