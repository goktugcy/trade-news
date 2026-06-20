import { createI18n } from 'vue-i18n';
import en from './en';
import tr from './tr';

export type SupportedLocale = 'en' | 'tr';

export const messages = { en, tr };

export function normalizeLocale(locale: unknown): SupportedLocale {
    return locale === 'tr' ? 'tr' : 'en';
}

export const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages,
    missingWarn: false,
    fallbackWarn: false,
});
