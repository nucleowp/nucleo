<?php

declare(strict_types=1);

namespace Nucleo\Seo;

use WP_Error;

/**
 * Dati dell'azienda per il JSON-LD della home (schema.org, di norma LocalBusiness), in un'unica opzione nucleo_business.
 * Le chiavi di schema.org note sono controllate una per una da check(); le altre sono ammesse se il nome è in camelCase
 * e il valore è semplice (testo, URL http, lista di testi, oggetto di scalari).
 */
final class Business
{
    private const OPTION = 'nucleo_business';

    private const DEFAULT_TYPE = 'LocalBusiness';

    private const TYPE_PATTERN = '/^[A-Z][A-Za-z]{2,60}$/D';

    private const DATE_PATTERN = '/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/D';

    private const HOURS_PATTERN = '/^(?:Mo|Tu|We|Th|Fr|Sa|Su)(?:-(?:Mo|Tu|We|Th|Fr|Sa|Su))? (?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d$/D';

    /** Chiavi aggiuntive: camelCase, da 2 a 41 caratteri. */
    private const EXTRA_KEY_PATTERN = '/^[a-z][A-Za-z]{1,40}$/D';

    /** Lunghezza massima di ogni stringa. */
    private const MAX_LENGTH = 300;

    /** Chiavi di address, tutte stringhe. */
    private const ADDRESS_KEYS = ['streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry'];

    /** Chiavi di geo, entrambe obbligatorie. */
    private const GEO_KEYS = ['latitude', 'longitude'];

    /** Chiavi note ammesse al primo livello, con controllo stretto; le altre devono rispettare EXTRA_KEY_PATTERN. */
    private const KEYS = ['@type', 'name', 'legalName', 'vatID', 'taxID', 'description', 'telephone', 'email', 'priceRange', 'foundingDate', 'url', 'logo', 'image', 'address', 'geo', 'openingHours', 'areaServed', 'sameAs'];

    /** @return array<string,mixed>|null */
    public static function get(): ?array
    {
        $value = get_option(self::OPTION, null);

        return is_array($value) ? $value : null;
    }

    /**
     * Controlla il valore senza scrivere nulla. Null è valido (nessun dato); altrimenti l'errore nomina la chiave sbagliata.
     */
    public static function check(mixed $value): ?WP_Error
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            return self::invalid('business');
        }
        if (!array_key_exists('name', $value)) {
            return self::invalid('name');
        }
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return self::invalid('business');
            }
            if (in_array($key, self::KEYS, true)) {
                if (!self::valid($key, $item)) {
                    return self::invalid($key);
                }
                continue;
            }
            if (preg_match(self::EXTRA_KEY_PATTERN, $key) !== 1 || !self::isExtra($item)) {
                return self::invalid($key);
            }
        }

        return null;
    }

    /**
     * Salva il valore già controllato, con @type di default. Null cancella i dati.
     * @param array<string,mixed>|null $value
     */
    public static function save(?array $value): void
    {
        if ($value === null) {
            delete_option(self::OPTION);

            return;
        }
        $value['@type'] ??= self::DEFAULT_TYPE;
        update_option(self::OPTION, $value, false);
    }

    /**
     * Nodo schema.org per il @graph della home: @id, url di default la home, indirizzo e coordinate con il proprio @type. Null se non c'è nulla.
     * @return array<string,mixed>|null
     */
    public static function node(): ?array
    {
        $value = self::get();
        if ($value === null) {
            return null;
        }

        $home = home_url('/');
        $node = [
            '@type' => $value['@type'] ?? self::DEFAULT_TYPE,
            '@id' => $home . '#business',
        ];
        foreach ($value as $key => $item) {
            if ($key === '@type') {
                continue;
            }
            $node[$key] = match ($key) {
                'address' => ['@type' => 'PostalAddress'] + $item,
                'geo' => ['@type' => 'GeoCoordinates'] + $item,
                default => $item,
            };
        }
        $node['url'] ??= $home;

        return $node;
    }

    private static function valid(string $key, mixed $item): bool
    {
        return match ($key) {
            '@type' => is_string($item) && preg_match(self::TYPE_PATTERN, $item) === 1,
            'foundingDate' => is_string($item) && preg_match(self::DATE_PATTERN, $item) === 1,
            'url', 'logo', 'image' => self::isHttpUrl($item),
            'sameAs' => self::isList($item, self::isHttpUrl(...)),
            'address' => self::isAddress($item),
            'geo' => self::isGeo($item),
            'openingHours' => self::isList($item, static fn (mixed $hours): bool => is_string($hours) && preg_match(self::HOURS_PATTERN, $hours) === 1),
            'areaServed' => self::isText($item) || self::isList($item, self::isText(...)),
            default => self::isText($item),
        };
    }

    /** Valore di una chiave aggiuntiva: testo (anche URL http), lista di testi o oggetto di scalari. */
    private static function isExtra(mixed $value): bool
    {
        if (!is_array($value)) {
            return self::isText($value);
        }
        if (array_is_list($value)) {
            return self::isList($value, self::isText(...));
        }

        return self::isScalarObject($value);
    }

    /** @param array<mixed> $value */
    private static function isScalarObject(array $value): bool
    {
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return false;
            }
            $scalar = is_int($item) || is_float($item) || is_bool($item);
            if (!$scalar && !self::isText($item)) {
                return false;
            }
        }

        return true;
    }

    private static function isText(mixed $value): bool
    {
        return is_string($value) && $value !== '' && mb_strlen($value) <= self::MAX_LENGTH;
    }

    private static function isHttpUrl(mixed $value): bool
    {
        if (!self::isText($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) wp_parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /** @param callable(mixed):bool $itemIsValid */
    private static function isList(mixed $value, callable $itemIsValid): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!$itemIsValid($item)) {
                return false;
            }
        }

        return true;
    }

    private static function isAddress(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $key => $item) {
            if (!is_string($key) || !in_array($key, self::ADDRESS_KEYS, true) || !self::isText($item)) {
                return false;
            }
        }

        return true;
    }

    private static function isGeo(mixed $value): bool
    {
        if (!is_array($value) || count($value) !== count(self::GEO_KEYS) || array_diff(array_keys($value), self::GEO_KEYS) !== []) {
            return false;
        }

        return self::isCoordinate($value['latitude'], 90) && self::isCoordinate($value['longitude'], 180);
    }

    private static function isCoordinate(mixed $value, int $limit): bool
    {
        return (is_int($value) || is_float($value)) && abs($value) <= $limit;
    }

    private static function invalid(string $key): WP_Error
    {
        /* translators: %s: name of a business field. */
        return new WP_Error('invalid_input', sprintf(__('"%s" is unknown or has an invalid value.', 'nucleo'), $key));
    }
}
