<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

use Locale;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Phrase;
use Magento\Framework\Phrase\Renderer\Placeholder;
use Magento\Framework\Phrase\RendererInterface;

/**
 * Makes Magento's __() work while the application is not installed.
 *
 * Magento's own Translate renderer needs the object manager and the database, so this
 * renderer reads the same dictionaries directly: the CSV of the installed language pack
 * (if it ships one) overlaid with this module's i18n/<locale>.csv.
 */
final class Translator implements RendererInterface
{
    public const SOURCE_LOCALE = 'en_US';

    /** @var array<string, string> */
    private array $dictionary;

    private readonly Placeholder $placeholder;

    private function __construct(private readonly string $locale)
    {
        $this->placeholder = new Placeholder();
        $this->dictionary = $locale === self::SOURCE_LOCALE
            ? []
            : array_replace(self::languagePackDictionary($locale), self::readCsv(self::moduleCsv($locale)));
    }

    public static function activate(string $locale): self
    {
        $translator = new self(isset(self::available()[$locale]) ? $locale : self::SOURCE_LOCALE);
        Phrase::setRenderer($translator);

        return $translator;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * Wizard languages: English plus every locale this module has an i18n CSV for.
     *
     * @return array<string, string> Locale code => language name in that language.
     */
    public static function available(): array
    {
        static $languages = null;
        if ($languages !== null) {
            return $languages;
        }

        $codes = [self::SOURCE_LOCALE];
        foreach (glob(self::i18nDir() . '/*.csv') ?: [] as $file) {
            $codes[] = basename($file, '.csv');
        }

        $codes = array_unique($codes);
        $languageCount = array_count_values(array_map(static fn (string $code): string => strtok($code, '_'), $codes));

        $languages = [];
        foreach ($codes as $code) {
            // "English", "Português"...; the region only when two variants of a language are offered.
            $name = !class_exists(Locale::class)
                ? $code
                : ($languageCount[strtok($code, '_')] > 1
                    ? Locale::getDisplayName($code, $code)
                    : Locale::getDisplayLanguage($code, $code));
            $languages[$code] = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
        }

        return $languages;
    }

    public function render(array $source, array $arguments)
    {
        $text = end($source);
        $source[key($source)] = $this->dictionary[$text] ?? $text;

        return $this->placeholder->render($source, $arguments);
    }

    /**
     * Strings the page's JavaScript needs, translated.
     *
     * @param string[] $texts
     * @return array<string, string>
     */
    public function exportForScript(array $texts): array
    {
        return array_combine($texts, array_map(fn (string $text): string => $this->dictionary[$text] ?? $text, $texts));
    }

    private static function i18nDir(): string
    {
        return dirname(__DIR__) . '/i18n';
    }

    private static function moduleCsv(string $locale): string
    {
        return self::i18nDir() . '/' . $locale . '.csv';
    }

    /**
     * Installed language pack for the locale (e.g. magento/language-pt_br), when it has CSV files.
     */
    private static function languagePackDictionary(string $locale): array
    {
        $dictionary = [];
        foreach ((new ComponentRegistrar())->getPaths(ComponentRegistrar::LANGUAGE) as $path) {
            $xml = is_file($path . '/language.xml') ? @simplexml_load_file($path . '/language.xml') : false;
            if ($xml === false || (string) $xml->code !== $locale) {
                continue;
            }
            foreach (glob($path . '/*.csv') ?: [] as $file) {
                $dictionary = array_replace($dictionary, self::readCsv($file));
            }
        }

        return $dictionary;
    }

    /**
     * Magento dictionary format: "source","translation"[,"type","module"].
     *
     * @return array<string, string>
     */
    private static function readCsv(string $file): array
    {
        $dictionary = [];
        $handle = is_file($file) ? fopen($file, 'rb') : false;
        if ($handle === false) {
            return $dictionary;
        }
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (isset($row[0], $row[1]) && $row[0] !== '') {
                $dictionary[$row[0]] = $row[1];
            }
        }
        fclose($handle);

        return $dictionary;
    }
}
