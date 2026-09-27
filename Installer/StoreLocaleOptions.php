<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

use Collator;
use IntlTimeZone;
use Locale;
use Magento\Framework\Locale\Config as LocaleConfig;
use Magento\Framework\Setup\Lists;
use ResourceBundle;

/**
 * Store language, currency and time zone choices: the same codes setup:install accepts
 * (Magento\Framework\Setup\Lists), labelled in the wizard language when intl has the name.
 */
final class StoreLocaleOptions
{
    private static ?Lists $lists = null;

    /**
     * @return array<string, string> Code => label, sorted by label.
     */
    public static function languages(string $displayLocale): array
    {
        return self::localize(
            self::lists()->getLocaleList(),
            static fn (string $code): string => Locale::getDisplayName($code, $displayLocale),
            $displayLocale
        );
    }

    /**
     * @return array<string, string> Code => label, sorted by label.
     */
    public static function currencies(string $displayLocale): array
    {
        $names = ResourceBundle::create($displayLocale, 'ICUDATA-curr')?->get('Currencies');

        return self::localize(
            self::lists()->getCurrencyList(),
            static function (string $code) use ($names): string {
                $name = $names?->get($code)?->get(1);
                return $name ? sprintf('%s (%s)', $name, $code) : '';
            },
            $displayLocale
        );
    }

    /**
     * @return array<string, string> Code => label, sorted by label.
     */
    public static function timezones(string $displayLocale): array
    {
        return self::localize(
            self::lists()->getTimezoneList(false),
            static function (string $code) use ($displayLocale): string {
                $zone = IntlTimeZone::createTimeZone($code);
                $name = $zone->getID() === 'Etc/Unknown' ? '' : $zone->getDisplayName(false, IntlTimeZone::DISPLAY_LONG, $displayLocale);
                return $name ? sprintf('%s (%s)', $name, $code) : '';
            },
            $displayLocale
        );
    }

    public static function isLanguage(string $code): bool
    {
        return isset(self::lists()->getLocaleList()[$code]);
    }

    public static function isCurrency(string $code): bool
    {
        return isset(self::lists()->getCurrencyList()[$code]);
    }

    public static function isTimezone(string $code): bool
    {
        return isset(self::lists()->getTimezoneList(false)[$code]);
    }

    private static function lists(): Lists
    {
        return self::$lists ??= new Lists(new LocaleConfig());
    }

    /**
     * Keeps Magento's label when intl has no translated name.
     *
     * @param array<string, string> $magentoList
     */
    private static function localize(array $magentoList, callable $label, string $displayLocale): array
    {
        $options = [];
        foreach ($magentoList as $code => $magentoLabel) {
            $name = class_exists(Locale::class) ? (string) $label((string) $code) : '';
            $options[$code] = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1) : $magentoLabel;
        }
        // Accent-aware alphabetical order of the wizard language ("Árabe" next to "Armênio", not after "Zulu").
        $collator = class_exists(Collator::class) ? new Collator($displayLocale) : null;
        uasort($options, static fn (string $a, string $b): int => $collator ? (int) $collator->compare($a, $b) : strcmp($a, $b));

        return $options;
    }
}
