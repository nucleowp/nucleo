<?php

declare(strict_types=1);

namespace Nucleo\Site;

defined('ABSPATH') || exit;

use Nucleo\Media\ImageFormats;
use Nucleo\Seo\Business;
use WP_Error;

/**
 * Titolo, tagline, homepage, pagina degli articoli, lingua, formati di data e ora, azienda (dati strutturati), icone del sito e formati immagine.
 * Le pagine si indicano per slug.
 */
final class SiteOptions
{
    /** Locale WordPress: it_IT, de_DE_formal, es, en_US. Si accetta anche il trattino (it-IT). */
    private const LOCALE_PATTERN = '/^[a-z]{2,3}(?:[_-][A-Z]{2})?(?:_[a-z0-9]+)?$/D';

    /** @return array{title:string,tagline:string,homepage:?string,postsPage:?string,language:string,dateFormat:string,timeFormat:string,business:?array<string,mixed>,icons:array<string,string>,imageFormats:list<string>,siteIcon:?string} */
    public static function get(): array
    {
        $locale = get_option('WPLANG');

        return [
            'title' => (string) get_option('blogname'),
            'tagline' => (string) get_option('blogdescription'),
            'homepage' => get_option('show_on_front') === 'page' ? self::slugOf((int) get_option('page_on_front')) : null,
            'postsPage' => self::slugOf((int) get_option('page_for_posts')),
            'language' => is_string($locale) && $locale !== '' ? $locale : 'en_US',
            'dateFormat' => (string) get_option('date_format'),
            'timeFormat' => (string) get_option('time_format'),
            'business' => Business::get(),
            'icons' => SiteIcons::all(),
            'imageFormats' => ImageFormats::enabled(),
            'siteIcon' => self::siteIconUrl(),
        ];
    }

    /** Chiavi di site.json "site". */
    public const KEYS = ['title', 'tagline', 'homepage', 'postsPage', 'language', 'dateFormat', 'timeFormat', 'business', 'icons', 'imageFormats', 'siteIcon'];

    /** Chiave di site.json → opzione WordPress dei formati PHP di data e ora (es. "j F Y", "H:i"). */
    private const FORMATS = ['dateFormat' => 'date_format', 'timeFormat' => 'time_format'];

    /**
     * Impronta per chiave dei valori dati: la base di site.json "siteBase". La lingua si confronta come locale (it-IT = it_IT).
     * Le chiavi assenti da $values non entrano.
     * @param array<string,mixed> $values
     * @return array<string,string>
     */
    public static function hashes(array $values): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $value = $key === 'language' ? (self::locale($values[$key]) ?? $values[$key]) : $values[$key];
                $out[$key] = sha1((string) wp_json_encode($value));
            }
        }

        return $out;
    }

    /**
     * Locale WordPress dalla lingua di site.json: "it-IT" diventa "it_IT". Null se il formato non è valido.
     */
    public static function locale(mixed $language): ?string
    {
        if (!is_string($language) || preg_match(self::LOCALE_PATTERN, $language) !== 1) {
            return null;
        }

        return str_replace('-', '_', $language);
    }

    /**
     * Controlla tipi, pagine e lingua senza scrivere nulla: lo usa il dry-run e, prima di scrivere, apply().
     * La lingua si controlla solo nel formato; con $language true anche la disponibilità del pacchetto.
     * @param array<string,mixed> $input
     * @param callable(string):?int $resolvePage slug → ID della pagina pubblicata, o null
     * @return array{homeId:?int,postsId:int,locale:?string,iconId:int}|WP_Error
     */
    public static function check(array $input, callable $resolvePage, bool $language = false): array|WP_Error
    {
        foreach (['title', 'tagline'] as $key) {
            if (array_key_exists($key, $input) && !is_string($input[$key])) {
                /* translators: %s: name of a settings field. */
                return new WP_Error('invalid_input', sprintf(__('"%s" must be a string.', 'nucleo'), $key));
            }
        }
        foreach (self::FORMATS as $key => $option) {
            // Si valida il valore come lo salva WordPress: un formato che la sanificazione cambia o svuota non si scrive.
            $raw = $input[$key] ?? null;
            if (array_key_exists($key, $input) && (!is_string($raw) || trim($raw) === '' || sanitize_option($option, $raw) !== $raw)) {
                /* translators: %s: name of a settings field. */
                return new WP_Error('invalid_input', sprintf(__('"%s" must be a PHP date format such as "j F Y" or "H:i".', 'nucleo'), $key));
            }
        }
        foreach (['homepage', 'postsPage'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null && !is_string($input[$key])) {
                /* translators: %s: name of a settings field. */
                return new WP_Error('invalid_input', sprintf(__('"%s" must be a string or null.', 'nucleo'), $key));
            }
        }

        $error = array_key_exists('business', $input) ? Business::check($input['business']) : null;
        $error ??= array_key_exists('icons', $input) ? SiteIcons::check($input['icons']) : null;
        $error ??= array_key_exists('imageFormats', $input) ? ImageFormats::check($input['imageFormats']) : null;
        if ($error !== null) {
            return $error;
        }

        $iconId = 0;
        if (array_key_exists('siteIcon', $input) && $input['siteIcon'] !== null) {
            $iconId = is_string($input['siteIcon']) ? self::imageId($input['siteIcon']) : 0;
            if ($iconId === 0) {
                return new WP_Error('invalid_input', __('"siteIcon" must be the URL of an image in the media library, or null to remove the favicon.', 'nucleo'));
            }
        }

        $locale = null;
        if (array_key_exists('language', $input)) {
            $locale = self::locale($input['language']);
            if ($locale === null) {
                return new WP_Error('invalid_input', __('"language" must be a WordPress locale such as it_IT or en_US.', 'nucleo'));
            }
        }

        $homeId = null;
        if (array_key_exists('homepage', $input) && is_string($input['homepage'])) {
            $homeId = $resolvePage($input['homepage']);
            if ($homeId === null) {
                /* translators: %s: slug of a page that is not published. */
                return new WP_Error('page_not_found', sprintf(__('No published page with slug: %s.', 'nucleo'), $input['homepage']));
            }
        }

        $postsId = (int) get_option('page_for_posts');
        if (array_key_exists('postsPage', $input)) {
            $postsId = 0;
            if (is_string($input['postsPage'])) {
                $postsId = $resolvePage($input['postsPage']) ?? -1;
                if ($postsId === -1) {
                    /* translators: %s: slug of a page that is not published. */
                    return new WP_Error('page_not_found', sprintf(__('No published page with slug: %s.', 'nucleo'), $input['postsPage']));
                }
            }
        }

        // ID 0 è la pagina ancora da creare del dry-run: due pagine nuove diverse non coincidono.
        if ($homeId !== null && $homeId > 0 && $homeId === $postsId) {
            return new WP_Error('same_page', __('The homepage and the posts page must be different pages.', 'nucleo'));
        }

        if ($language && $locale !== null && !self::languageReady($locale)) {
            return self::languageError($locale);
        }

        return ['homeId' => $homeId, 'postsId' => $postsId, 'locale' => $locale, 'iconId' => $iconId];
    }

    /**
     * Controlla tutto prima di scrivere: un errore su titolo, tagline o pagine non cambia nulla. Le chiavi assenti restano come sono.
     * La lingua si applica per ultima: se il pacchetto non si installa, le altre impostazioni restano scritte e l'errore riguarda solo la lingua.
     * @param array<string,mixed> $input
     * @param callable(string):?int $resolvePage slug → ID della pagina pubblicata, o null
     * @return true|WP_Error
     */
    public static function apply(array $input, callable $resolvePage): true|WP_Error
    {
        $checked = self::check($input, $resolvePage);
        if ($checked instanceof WP_Error) {
            return $checked;
        }

        if (array_key_exists('title', $input)) {
            update_option('blogname', sanitize_text_field($input['title']));
        }
        if (array_key_exists('tagline', $input)) {
            update_option('blogdescription', sanitize_text_field($input['tagline']));
        }
        if (array_key_exists('homepage', $input)) {
            if ($checked['homeId'] !== null) {
                update_option('show_on_front', 'page');
                update_option('page_on_front', $checked['homeId']);
            } else {
                update_option('show_on_front', 'posts');
            }
        }
        if (array_key_exists('postsPage', $input)) {
            update_option('page_for_posts', $checked['postsId']);
        }
        foreach (self::FORMATS as $key => $option) {
            if (array_key_exists($key, $input)) {
                update_option($option, sanitize_option($option, $input[$key]));
            }
        }

        if (array_key_exists('business', $input)) {
            Business::save(is_array($input['business']) ? $input['business'] : null);
        }
        if (array_key_exists('icons', $input)) {
            SiteIcons::save(is_array($input['icons']) ? $input['icons'] : []);
        }
        if (array_key_exists('imageFormats', $input)) {
            ImageFormats::save(is_array($input['imageFormats']) ? $input['imageFormats'] : []);
        }
        // Favicon: WordPress la stampa da site_icon (wp_site_icon); 0 la toglie.
        if (array_key_exists('siteIcon', $input)) {
            update_option('site_icon', $checked['iconId']);
        }

        $locale = $checked['locale'];
        // La lingua già impostata senza pacchetto installato si scarica comunque.
        if ($locale === null || (self::currentLocale() === $locale && self::installed($locale))) {
            return true;
        }
        // Il pacchetto per WordPress.org non installa pacchetti di lingua da remoto: la lingua si cambia in Impostazioni > Generali.

        return self::languageError($locale);
    }

    /** URL della favicon (opzione site_icon), null se non c'è. */
    private static function siteIconUrl(): ?string
    {
        $url = wp_get_attachment_url((int) get_option('site_icon'));

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Immagine della libreria media dal suo URL; se l'URL è di un altro sito (site.json importato), dal nome del file.
     * 0 se non c'è o non è un'immagine.
     */
    private static function imageId(string $url): int
    {
        $id = attachment_url_to_postid($url);
        if ($id === 0) {
            $file = sanitize_file_name(wp_basename((string) wp_parse_url($url, PHP_URL_PATH)));
            $found = $file === '' ? [] : get_posts([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => 1,
                'fields' => 'ids',
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- solo quando l'URL non è di questo sito.
                'meta_query' => [['key' => '_wp_attached_file', 'value' => '/' . $file, 'compare' => 'LIKE']],
            ]);
            $id = (int) ($found[0] ?? 0);
        }

        return $id > 0 && wp_attachment_is_image($id) ? $id : 0;
    }

    private static function currentLocale(): string
    {
        $locale = get_option('WPLANG');

        return is_string($locale) && $locale !== '' ? $locale : 'en_US';
    }

    private static function languageError(string $locale): WP_Error
    {
        /* translators: %s: WordPress locale such as it_IT. */
        return new WP_Error('language_unavailable', sprintf(__('Language pack not available for %s: install it from Settings > General (it needs the install_languages capability and file access), then import again. The other settings were applied.', 'nucleo'), $locale));
    }

    /** true se la lingua è quella attuale, è installata, o si può scaricare: permesso, file scrivibili e pacchetto nell'elenco di WordPress.org. */
    private static function languageReady(string $locale): bool
    {
        $ready = self::installed($locale);

        return $ready;
    }

    /** L'inglese di base c'è sempre; le altre lingue se il pacchetto è nella cartella delle lingue. */
    private static function installed(string $locale): bool
    {
        return $locale === 'en_US' || in_array($locale, get_available_languages(), true);
    }


    /** Slug (percorso per una pagina figlia) della pagina con questo ID, o null se l'ID è 0 o la pagina non esiste più. */
    private static function slugOf(int $id): ?string
    {
        if ($id <= 0) {
            return null;
        }
        // Una pagina figlia porta il percorso ("servizi/consulenza"), come nelle voci dei menu.
        $slug = get_post($id) instanceof \WP_Post ? get_page_uri($id) : '';

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
