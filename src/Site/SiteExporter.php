<?php

declare(strict_types=1);

namespace Nucleo\Site;

use Nucleo\Assets\RulesPublisher;
use Nucleo\Content\ContentReader;
use Nucleo\Content\TermItems;
use Nucleo\Services;
use Nucleo\Support\Optional;
use WP_Post;
use WP_Term;
use WP_User;

/** Esporta il sito come file JSON: token, breakpoint, pagine, post, layout, sezioni, regole, media, menu e impostazioni. */
final class SiteExporter
{
    /** Cartella per tipo di post Nucleo. */
    public const FOLDERS = [
        'page' => 'pages',
        'post' => 'posts',
        'nucleo_layout' => 'layouts',
        'nucleo_section' => 'sections',
    ];

    /** Classe del plugin multilingua, nel package Pro: dalla Free si chiama solo tramite Optional. */
    private const MULTILINGUAL = 'Nucleo\\Integrations\\Multilingual';

    /** Ordine delle chiavi di un elemento in content/, come in content-pull.js. */
    private const ITEM_KEYS = ['title', 'slug', 'status', 'date', 'excerpt', 'body', 'featuredImage', 'terms', 'fields', 'parent', 'menuOrder'];

    /**
     * @param list<array{menu:string,label:string,reason:string}> $skipped voci di menu non esportate (bersaglio non pubblicato o cancellato)
     * @return array<string,string> percorso relativo → contenuto JSON
     */
    public static function files(array &$skipped = []): array
    {
        $media = self::mediaLibrary();
        $mediaRefs = [];
        foreach ($media as $item) {
            $mediaRefs[$item['url']] = 'media:' . $item['file'];
        }

        $files = [];
        $base = [];
        /** @var array<int,array{path:string,post:WP_Post,postType:string,document:array<string,mixed>}> ID → documento esportato */
        $exported = [];
        foreach (self::FOLDERS as $postType => $folder) {
            foreach (self::posts($postType) as $post) {
                $found = Services::documents()->get($post->ID);
                if ($found === null) {
                    continue;
                }

                $exported[$post->ID] = ['path' => '', 'post' => $post, 'postType' => $postType, 'document' => $found['document']];
            }
        }
        $exported = self::withUniquePaths($exported);
        foreach ($exported as $item) {
            $base[$item['path']] = self::fingerprint($item['document'], self::parentPath($item['post']), $item['post']->post_title, $item['post']->post_status);
        }

        // I percorsi di tutti i documenti servono alle traduzioni: prima si raccolgono, poi si scrivono.
        $paths = array_map(static fn (array $item): string => $item['path'], $exported);
        foreach ($exported as $id => $item) {
            $doc = SymbolicRefs::mapMedia($item['document'], static fn (string $value): string => $mediaRefs[$value] ?? $value);
            $doc['wp'] = [
                'title' => $item['post']->post_title,
                'slug' => self::slugOf($item['post']),
                'status' => $item['post']->post_status,
                'postType' => $item['postType'],
            ];
            // Pagine: percorso del genitore, che il push risolve e crea prima dei figli; null per il primo livello.
            if ($item['postType'] === 'page') {
                $parentPath = self::parentPath($item['post']);
                $doc['wp']['parent'] = $parentPath !== '' ? $parentPath : null;
            }
            $lang = Optional::call(self::MULTILINGUAL, 'postLanguage', $id);
            if (is_string($lang)) {
                $doc['wp']['lang'] = $lang;
                $translations = self::translationPaths($id, $paths);
                if (count($translations) > 1) {
                    $doc['wp']['translations'] = $translations;
                }
            }
            $files[$item['path']] = self::json(SymbolicRefs::export($doc, self::name(...)));
        }

        $files['rules/assets.json'] = self::json(RulesPublisher::rules());
        $files['media/manifest.json'] = self::json($media);

        // Un file per menu: il nome del file è lo slug del menu, che è anche la chiave per ritrovarlo all'import.
        foreach (Menus::export($skipped) as $slug => $menu) {
            $files['menus/' . $slug . '.json'] = self::json($menu);
        }

        // Contenuti e termini nel formato di content-pull: un elemento di page o post già esportato come documento non si ripete.
        $files += self::contentFiles($files);

        // Azienda, icone e formati delle immagini solo se impostati: un sito senza resta con il site.json di prima.
        $site = array_filter(
            SiteOptions::get(),
            static fn (mixed $value, string $key): bool => !in_array($key, ['business', 'icons', 'imageFormats', 'siteIcon'], true) || ($value !== null && $value !== []),
            ARRAY_FILTER_USE_BOTH,
        );

        return ['site.json' => self::json([
            'schema' => 1,
            'exportedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'base' => $base,
            'tokens' => get_option('nucleo_tokens', []),
            // Impronta dei token all'export: l'import non sovrascrive i token cambiati sul sito se in locale sono uguali.
            'tokensBase' => SiteImporter::tokensHash(get_option('nucleo_tokens', [])) ?? '',
            'breakpoints' => Services::breakpoints()->minWidths,
            'site' => $site,
            // Impronta per chiave delle impostazioni all'export: l'import non riporta indietro quelle cambiate sul sito.
            'siteBase' => SiteOptions::hashes($site),
        ])] + $files;
    }

    /**
     * Impronta del documento salvato, uguale in export e in import: serve a rilevare le modifiche sul sito.
     * @param array<string,mixed> $doc
     */
    public static function hash(array $doc): string
    {
        // blocks lo scrive il salvataggio (versioni dei blocchi): non è contenuto e non deve segnare un cambio.
        unset($doc['blocks']);

        return sha1(json_encode(Services::normalizer()->normalize($doc), JSON_THROW_ON_ERROR));
    }

    /**
     * Impronta della base di site.json: il documento e, per una pagina figlia, il percorso del genitore.
     * Una pagina di primo livello ha la stessa impronta di hash(): le basi scritte prima restano valide.
     * Con titolo o stato (v2) l'impronta include anche quelli: le basi senza prefisso 'v2:' sono v1.
     * @param array<string,mixed> $doc
     */
    public static function fingerprint(array $doc, string $parentPath = '', ?string $title = null, ?string $status = null): string
    {
        $hash = self::hash($doc);
        $v1 = $parentPath === '' ? $hash : sha1($hash . "\0parent:" . $parentPath);
        if ($title === null && $status === null) {
            return $v1;
        }

        return 'v2:' . sha1($v1 . "\0title:" . ($title ?? '') . "\0status:" . ($status ?? ''));
    }

    /** Percorso della pagina genitore ("chi-siamo/azienda"), vuoto per una pagina di primo livello o un altro tipo. */
    private static function parentPath(WP_Post $post): string
    {
        $path = $post->post_type === 'page' && $post->post_parent > 0 ? get_page_uri($post->post_parent) : '';

        return is_string($path) ? $path : '';
    }

    /** Percorso del file di un post Nucleo, es. "pages/home.json". */
    public static function pathOf(WP_Post $post): string
    {
        return (self::FOLDERS[$post->post_type] ?? 'other') . '/' . self::slugOf($post) . '.json';
    }

    /** Lo slug del post, o un ricavato dal titolo per le bozze che non lo hanno ancora. */
    public static function slugOf(WP_Post $post): string
    {
        if ($post->post_name !== '') {
            return $post->post_name;
        }

        $slug = sanitize_title($post->post_title);

        return $slug !== '' ? $slug : 'post-' . $post->ID;
    }

    /**
     * Lingua → percorso delle traduzioni esportate, la pagina stessa compresa.
     * @param array<int,string> $paths ID → percorso dei soli documenti esportati
     * @return array<string,string>
     */
    private static function translationPaths(int $postId, array $paths): array
    {
        $group = Optional::call(self::MULTILINGUAL, 'translations', $postId);
        if (!is_array($group)) {
            return [];
        }

        $translations = [];
        foreach ($group as $lang => $id) {
            if (isset($paths[(int) $id])) {
                $translations[(string) $lang] = $paths[(int) $id];
            }
        }

        return $translations;
    }

    /** @return array<int,WP_Post> */
    private static function posts(string $postType): array
    {
        return get_posts([
            'post_type' => $postType,
            'post_status' => 'any',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
    }

    /**
     * Gli allegati della libreria che hanno un file su disco.
     * @return list<array{file:string,url:string,alt:string,sha1:string}>
     */
    private static function mediaLibrary(): array
    {
        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $media = [];
        foreach ($attachments as $attachment) {
            // Il file originale, non la copia "-scaled": nome e sha1 coincidono con il file caricato.
            $path = (wp_get_original_image_path($attachment->ID) ?: get_attached_file($attachment->ID));
            $url = wp_get_attachment_url($attachment->ID);
            $sha1 = is_string($path) && is_file($path) ? sha1_file($path) : false;
            if (!is_string($path) || !is_string($url) || $sha1 === false) {
                continue;
            }

            $media[] = [
                'file' => basename($path),
                'url' => $url,
                'alt' => (string) get_post_meta($attachment->ID, '_wp_attachment_image_alt', true),
                'sha1' => $sha1,
            ];
        }

        return $media;
    }

    /** Nome simbolico di un ID, o null se l'oggetto non esiste o non è esportabile. */
    private static function name(string $kind, int $id): ?string
    {
        return match ($kind) {
            'post' => self::postName($id),
            'section' => self::postName($id, 'nucleo_section'),
            'term' => self::termName($id),
            'author' => self::authorName($id),
            'menu' => self::menuName($id),
            default => null,
        };
    }

    private static function postName(int $id, ?string $expected = null): ?string
    {
        $post = get_post($id);
        if (!$post instanceof WP_Post) {
            return null;
        }

        if ($expected !== null) {
            return $post->post_type === $expected ? 'section:' . self::slugOf($post) : null;
        }

        return match ($post->post_type) {
            'page' => 'page:' . self::slugOf($post),
            'post' => 'post:' . self::slugOf($post),
            default => null,
        };
    }

    private static function termName(int $id): ?string
    {
        $term = get_term($id);

        return $term instanceof WP_Term ? 'term:' . $term->taxonomy . ':' . $term->slug : null;
    }

    private static function authorName(int $id): ?string
    {
        $user = get_userdata($id);

        return $user instanceof WP_User ? 'author:' . $user->user_login : null;
    }

    private static function menuName(int $id): ?string
    {
        $menu = wp_get_nav_menu_object($id);

        return $menu instanceof WP_Term ? 'menu:' . $menu->slug : null;
    }

    /**
     * Percorsi unici dei documenti: due documenti con lo stesso slug (figlie di genitori diversi, traduzioni) non si
     * sovrascrivono. Il percorso base resta se è l'unico; altrimenti si prova il genitore, poi la lingua, poi l'ID.
     * @param array<int,array{path:string,post:WP_Post,postType:string,document:array<string,mixed>}> $exported
     * @return array<int,array{path:string,post:WP_Post,postType:string,document:array<string,mixed>}>
     */
    private static function withUniquePaths(array $exported): array
    {
        $sizes = [];
        foreach ($exported as $item) {
            $base = self::pathOf($item['post']);
            $sizes[$base] = ($sizes[$base] ?? 0) + 1;
        }

        $paths = [];
        $used = [];
        foreach ($exported as $id => $item) {
            $base = self::pathOf($item['post']);
            if ($sizes[$base] === 1) {
                $paths[$id] = $base;
                $used[$base] = true;
            }
        }
        foreach ($exported as $id => $item) {
            if (isset($paths[$id])) {
                continue;
            }
            foreach (self::variants($item['post'], $item['postType'], $id) as $candidate) {
                if (!isset($used[$candidate])) {
                    $paths[$id] = $candidate;
                    $used[$candidate] = true;
                    break;
                }
            }
        }

        foreach ($exported as $id => $item) {
            $exported[$id]['path'] = $paths[$id];
        }

        return $exported;
    }

    /**
     * Percorsi alternativi di un documento con lo slug condiviso, in ordine di preferenza:
     * "pages/genitore/slug.json", "pages/slug.<lingua>.json", "pages/slug-<ID>.json".
     * @return list<string>
     */
    private static function variants(WP_Post $post, string $postType, int $id): array
    {
        $folder = self::FOLDERS[$postType] ?? 'other';
        $slug = self::slugOf($post);
        $variants = [];
        $parent = self::parentPath($post);
        if ($parent !== '') {
            $variants[] = $folder . '/' . $parent . '/' . $slug . '.json';
        }
        $lang = Optional::call(self::MULTILINGUAL, 'postLanguage', $id);
        if (is_string($lang) && $lang !== '') {
            $variants[] = $folder . '/' . $slug . '.' . $lang . '.json';
        }
        $variants[] = $folder . '/' . $slug . '-' . $id . '.json';

        return $variants;
    }

    /**
     * content/<tipo>/<slug>.json e terms/<tassonomia>.json come content-pull: un elemento per file, senza type e managedBy,
     * i termini delle tassonomie modellate. Un elemento di page o post con lo stesso file di un documento si salta.
     * @param array<string,string> $documents percorso → contenuto dei documenti già esportati
     * @return array<string,string> percorso relativo → contenuto JSON
     */
    private static function contentFiles(array $documents): array
    {
        $files = [];
        foreach (ContentReader::types() as $type) {
            if (($type['modeled'] ?? false) !== true) {
                continue;
            }
            $name = (string) $type['type'];
            $page = 1;
            do {
                $result = ContentReader::list(['type' => $name, 'full' => true, 'perPage' => 100, 'page' => $page]);
                foreach ($result['items'] as $item) {
                    $slug = $item['slug'] ?? '';
                    if (!is_string($slug) || $slug === '' || str_contains($slug, '/') || !empty($item['managedBy'])) {
                        continue;
                    }
                    if (in_array($name, ['page', 'post'], true) && isset($documents[self::FOLDERS[$name] . '/' . $slug . '.json'])) {
                        continue;
                    }
                    $files['content/' . $name . '/' . $slug . '.json'] = self::contentJson(self::orderItem($item));
                }
                $page++;
            } while ($page <= (int) ($result['pages'] ?? 1));
        }

        foreach (TermItems::taxonomies() as $taxonomy) {
            if (($taxonomy['modeled'] ?? false) !== true) {
                continue;
            }
            $name = (string) $taxonomy['taxonomy'];
            $terms = array_map(static function (array $term): array {
                // Senza campi, l'oggetto vuoto va in JSON come {} e non come [].
                if (array_key_exists('fields', $term) && $term['fields'] === []) {
                    $term['fields'] = (object) [];
                }

                return $term;
            }, TermItems::get($name));
            $files['terms/' . $name . '.json'] = self::contentJson($terms);
        }

        return $files;
    }

    /**
     * Chiavi nell'ordine del contratto content-items, poi le altre; senza type e managedBy.
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function orderItem(array $item): array
    {
        $out = [];
        foreach (self::ITEM_KEYS as $key) {
            if (array_key_exists($key, $item)) {
                $out[$key] = $item[$key];
            }
        }
        foreach ($item as $key => $value) {
            if (!in_array($key, self::ITEM_KEYS, true) && $key !== 'type' && $key !== 'managedBy') {
                $out[$key] = $value;
            }
        }
        foreach (['fields', 'terms'] as $key) {
            if (($out[$key] ?? null) === []) {
                $out[$key] = (object) [];
            }
        }

        return $out;
    }

    /** JSON come content-pull.js: due spazi per livello (PHP ne usa quattro) e a capo finale. */
    private static function contentJson(mixed $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // Nel JSON prettificato i rientri sono a inizio riga e le stringhe non contengono a capo: basta dimezzarli.
        $indented = preg_replace_callback('/^(?: {4})+/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 2)), $json);

        return ($indented ?? $json) . "\n";
    }

    /** @param array<string,mixed>|list<array<string,mixed>> $data */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
