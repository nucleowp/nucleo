<?php

declare(strict_types=1);

namespace Nucleo\Quality;

use Nucleo\Blocks\Registry;
use Nucleo\Compiler\BlockBundle;
use Nucleo\Forms\ConsequentialActions;
use Nucleo\Layout\LayoutResolver;
use Nucleo\Runtime\Areas;
use Nucleo\Layout\RequestContext;
use Nucleo\Services;
use Nucleo\Tokens\TokenSet;

/**
 * Compone le pagine (layout, contenuto, globali) in un unico albero semantico.
 * Legge la sezione `semantics` dei manifest; non produce né analizza HTML.
 *
 * @phpstan-type HeadingEntry array{doc:string,id:string,level:int,text:string,visible:array<string,bool>}
 * @phpstan-type ImageEntry array{doc:string,id:string,src:string,alt:string|null,decorative:bool,lazy:bool,lcp:bool}
 * @phpstan-type LinkEntry array{doc:string,id:string,href:string,text:string,ariaLabel:string|null}
 * @phpstan-type PairEntry array{doc:string,id:string,breakpoint:string,fg:string,bg:string,large:bool,graphic:bool}
 * @phpstan-type ConditionalEntry array{doc:string,id:string,when:list<string>}
 * @phpstan-type AnchorEntry array{doc:string,id:string,anchor:string}
 * @phpstan-type FixedEntry array{doc:string,id:string,zIndex:bool}
 * @phpstan-type ButtonEntry array{doc:string,id:string,text:string,href:string}
 * @phpstan-type StyleEntry array{doc:string,id:string,prop:string,value:string}
 * @phpstan-type FormEntry array{doc:string,id:string,actions:list<array{type:string,target:string}>}
 * @phpstan-type LayoutEntry array{doc:string,id:string,area:string,conditions:string}
 * @phpstan-type NodeEntry array{doc:string,id:string,type:string,node:array<string,mixed>,visible:array<string,bool>}
 * @phpstan-type Acc array{headings:list<HeadingEntry>,images:list<ImageEntry>,links:list<LinkEntry>,pairs:list<PairEntry>,landmarks:list<string>,conditional:list<ConditionalEntry>,anchors:list<AnchorEntry>,fixed:list<FixedEntry>,buttons:list<ButtonEntry>,styles:list<StyleEntry>,forms:list<FormEntry>,layouts:list<LayoutEntry>,nodes:list<NodeEntry>}
 */
final class PageComposer
{
    private const HEX = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D';

    /** Blocchi che non disegnano testo: il loro colore non è un colore di testo. */
    private const NON_TEXT = ['nc/icon', 'nc/image', 'nc/divider', 'nc/spacer', 'nc/video', 'nc/post-image'];

    /** Contenitori senza testo proprio: contano solo i figli. */
    private const BOXES = ['nc/container', 'nc/section'];

    /** Aree di layout che compongono la pagina: prima del contenuto, poi dopo. */
    private const BEFORE_AREAS = ['header', 'before-content'];
    private const AFTER_AREAS = ['after-content', 'footer'];

    /** Breakpoint in ordine crescente: un breakpoint mancante eredita il più piccolo precedente. */
    private const BREAKPOINTS = ['base', 'md', 'lg', 'xl'];

    public function __construct(
        private readonly Registry $registry,
        private readonly TokenSet $tokens,
    ) {
    }

    /**
     * @param array<string,array<string,mixed>> $docs chiavi "tipo:id" (es. "layout:88", "page:120"); l'ordine delle chiavi è l'ordine della pagina
     * @param int|null $postId post della pagina: se presente, la pagina misura il peso (css e js); null lascia weight vuoto
     * @param int|null $cssBytes byte del CSS del post appena compilato; null legge il file salvato
     */
    public function compose(array $docs, ?int $postId = null, ?int $cssBytes = null): ComposedPage
    {
        $acc = ['headings' => [], 'images' => [], 'links' => [], 'pairs' => [], 'landmarks' => [], 'conditional' => [], 'anchors' => [], 'fixed' => [], 'buttons' => [], 'styles' => [], 'forms' => [], 'layouts' => [], 'nodes' => []];

        foreach ($docs as $doc => $document) {
            $root = $document['root'] ?? null;
            if (!is_array($root)) {
                continue;
            }

            // Un layout è riconoscibile dal tipo di documento: area e condizioni servono alla regola di conflitto.
            $meta = is_array($document['meta'] ?? null) ? $document['meta'] : [];
            if (str_starts_with($doc, 'layout:') && is_string($meta['area'] ?? null)) {
                $acc['layouts'][] = [
                    'doc' => $doc,
                    'id' => is_string($root['id'] ?? null) ? $root['id'] : '',
                    'area' => $meta['area'],
                    'conditions' => (string) json_encode($meta['conditions'] ?? null),
                ];
            }

            $this->walk($doc, $root, [], $acc);
        }

        return new ComposedPage(
            headings: $acc['headings'],
            images: self::assignImageRoles($acc['images']),
            links: $acc['links'],
            colorPairs: $acc['pairs'],
            landmarks: $acc['landmarks'],
            conditional: $acc['conditional'],
            anchors: $acc['anchors'],
            fixed: $acc['fixed'],
            buttons: $acc['buttons'],
            styles: $acc['styles'],
            forms: $acc['forms'],
            layouts: $acc['layouts'],
            nodes: $acc['nodes'],
            weight: $postId === null ? null : $this->weight($docs, $acc['nodes'], $postId, $cssBytes),
        );
    }

    /**
     * Pagina con header, footer e le altre aree di layout risolte per il post; senza layout è uguale a compose([$key => $doc]).
     *
     * @param array<string,mixed> $doc
     */
    public function composeForPost(int $postId, string $key, array $doc, ?int $cssBytes = null): ComposedPage
    {
        // Layout e sezioni non ricevono altri layout: si controllano da soli.
        if ($postId <= 0 || in_array(get_post_type($postId), [\Nucleo\Layout\PostTypes::LAYOUT, \Nucleo\Layout\PostTypes::SECTION], true)) {
            return $this->compose([$key => $doc], $postId > 0 ? $postId : null, $cssBytes);
        }
        return $this->composeWithLayouts(RequestContext::fromUrl((string) get_permalink($postId)), $key, $doc, $postId, $cssBytes);
    }

    /**
     * Pagina non ancora salvata (doc-validate): header, footer e altre aree dei layout di una pagina generica del sito.
     * I layout legati a un post, a un termine o alla home non entrano: la pagina non ha ancora id, termini e indirizzo.
     *
     * @param array<string,mixed> $doc
     */
    public function composeForNewPage(string $key, array $doc, string $type = 'page'): ComposedPage
    {
        return $this->composeWithLayouts(['kind' => 'singular', 'id' => 0, 'type' => $type, 'terms' => [], 'front' => false], $key, $doc, null);
    }

    /**
     * @param array<string,mixed> $context contesto di RequestContext
     * @param array<string,mixed> $doc
     */
    private function composeWithLayouts(array $context, string $key, array $doc, ?int $postId, ?int $cssBytes = null): ComposedPage
    {
        $artifact = Services::store()->get('layouts');
        $resolver = new LayoutResolver(
            is_array($artifact['map'] ?? null) ? $artifact['map'] : [],
            is_array($artifact['exclude'] ?? null) ? $artifact['exclude'] : [],
        );

        // Stesse regole del frontend: layout "none", scelte della pagina, product al posto di single.
        $plan = Areas::plan($resolver, $context, $postId ?? 0, $doc, class_exists('WooCommerce'));
        $docs = [];
        foreach (self::BEFORE_AREAS as $area) {
            $this->addLayout($docs, $plan[$area] ?? null);
        }
        // Il layout single o product avvolge il contenuto: entra subito prima del documento.
        $this->addLayout($docs, $plan['product'] ?? $plan['single'] ?? null);
        $docs[$key] = $doc;
        foreach (self::AFTER_AREAS as $area) {
            $this->addLayout($docs, $plan[$area] ?? null);
        }

        return $this->compose($docs, $postId, $cssBytes);
    }

    /**
     * Byte che Nucleo serve alla pagina: bundle dei blocchi, CSS del post e dei layout; loader e script dei blocchi.
     * File mancanti contano 0.
     *
     * @param array<string,array<string,mixed>> $docs
     * @param list<NodeEntry> $nodes
     * @return array{css:int,js:int}
     */
    private function weight(array $docs, array $nodes, int $postId, ?int $cssBytes = null): array
    {
        $css = self::size(BlockBundle::file()) + ($cssBytes ?? self::cssSize($postId));
        foreach (array_keys($docs) as $doc) {
            if (!str_starts_with($doc, 'layout:')) {
                continue;
            }
            $layoutId = (int) substr($doc, strlen('layout:'));
            if ($layoutId !== $postId) {
                $css += self::cssSize($layoutId);
            }
        }

        $js = self::size(NUCLEO_DIR . 'assets/js/loader.js');
        foreach (array_unique(array_column($nodes, 'type')) as $type) {
            $manifest = $this->registry->get($type);
            $script = $manifest?->assets['script'] ?? null;
            if ($manifest !== null && is_string($script)) {
                $js += self::size($manifest->dir . '/' . $script);
            }
        }

        return ['css' => $css, 'js' => $js];
    }

    /** Dimensione del CSS del post da _nucleo_rt (cssFile); 0 se il meta manca. */
    private static function cssSize(int $postId): int
    {
        $rt = get_post_meta($postId, '_nucleo_rt', true);

        return is_array($rt) && is_string($rt['cssFile'] ?? null) ? self::size($rt['cssFile']) : 0;
    }

    private static function size(?string $file): int
    {
        return $file !== null && is_file($file) ? (int) filesize($file) : 0;
    }

    /**
     * @param array<string,array<string,mixed>> $docs
     * @param array{layout:int,matched:string}|null $hit
     */
    private function addLayout(array &$docs, ?array $hit): void
    {
        if ($hit === null || isset($docs['layout:' . $hit['layout']])) {
            return;
        }
        $found = Services::documents()->get($hit['layout']);
        if ($found === null) {
            return;
        }
        $docs['layout:' . $hit['layout']] = Services::documents()->prepare($found['document'], $hit['layout'])->doc;
    }

    /**
     * @param array<mixed> $node
     * @param array<string,string> $inheritedBg sfondo degli antenati, per breakpoint
     * @param Acc $acc
     * @param array<string,bool> $inheritedVisible visibilità degli antenati, per breakpoint
     */
    private function walk(string $doc, array $node, array $inheritedBg, array &$acc, array $inheritedVisible = []): void
    {
        $id = is_string($node['id'] ?? null) ? $node['id'] : '';
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $manifest = $this->registry->get($type);
        $semantics = $manifest !== null ? $manifest->semantics : [];
        $style = is_array($node['style'] ?? null) ? $node['style'] : [];

        // Sfondo dichiarato dal nodo o dal suo antenato più vicino, breakpoint per breakpoint; il default è il token del tema.
        $bgs = array_replace($inheritedBg, self::responsive($style['background'] ?? null));

        // Un nodo è visibile in un breakpoint solo se lo sono anche tutti i suoi antenati.
        $visible = self::visibleMap($node);
        foreach ($inheritedVisible as $breakpoint => $flag) {
            $visible[$breakpoint] = $flag && ($visible[$breakpoint] ?? true);
        }
        $flat = $node;
        unset($flat['children']);
        /** @var array<string,mixed> $flat */
        $acc['nodes'][] = ['doc' => $doc, 'id' => $id, 'type' => $type, 'node' => $flat, 'visible' => $visible];

        $level = null;
        if (isset($semantics['heading']) && is_array($semantics['heading'])) {
            $h = $semantics['heading'];
            $raw = self::field($node, $h['levelFrom'] ?? null);
            if (is_int($raw) && $raw >= 1 && $raw <= 6) {
                $level = $raw;
                $acc['headings'][] = [
                    'doc' => $doc,
                    'id' => $id,
                    'level' => $raw,
                    'text' => self::text(self::field($node, $h['textFrom'] ?? null)),
                    'visible' => self::visibleMap($node),
                ];
            }
        }

        if (isset($semantics['image']) && is_array($semantics['image'])) {
            $im = $semantics['image'];
            $src = self::field($node, $im['srcFrom'] ?? null);
            $alt = self::field($node, $im['altFrom'] ?? null);
            $acc['images'][] = [
                'doc' => $doc,
                'id' => $id,
                'src' => is_string($src) ? $src : '',
                'alt' => is_string($alt) ? $alt : null,
                'decorative' => self::field($node, $im['decorativeFrom'] ?? null) === true,
                'lazy' => false,
                'lcp' => false,
            ];
        }

        if (isset($semantics['link']) && is_array($semantics['link'])) {
            $ln = $semantics['link'];
            $href = self::field($node, $ln['hrefFrom'] ?? null);
            $acc['links'][] = [
                'doc' => $doc,
                'id' => $id,
                'href' => is_string($href) ? $href : '',
                'text' => self::text(self::field($node, $ln['textFrom'] ?? null)),
                'ariaLabel' => self::ariaLabel($node),
            ];
        }

        if (isset($semantics['landmark']) && is_array($semantics['landmark'])) {
            $lm = $semantics['landmark'];
            // tag: landmark fisso del blocco (nc/menu rende sempre <nav>); ifFrom: campo che deve essere pieno, o il blocco non rende nulla.
            $tag = is_string($lm['tag'] ?? null) ? $lm['tag'] : self::field($node, $lm['tagFrom'] ?? null);
            $shown = !isset($lm['ifFrom']) || !empty(self::field($node, $lm['ifFrom']));
            if (is_string($tag) && $tag !== '' && $shown) {
                $acc['landmarks'][] = $tag;
            }
        }

        $when = self::whenConditions($node);
        if ($when !== []) {
            $acc['conditional'][] = ['doc' => $doc, 'id' => $id, 'when' => $when];
        }

        $anchor = self::field($node, 'settings.anchor');
        if (is_string($anchor) && trim($anchor) !== '') {
            $acc['anchors'][] = ['doc' => $doc, 'id' => $id, 'anchor' => trim($anchor)];
        }

        $position = $style['position'] ?? null;
        if (in_array('fixed', is_array($position) ? $position : [$position], true)) {
            $acc['fixed'][] = ['doc' => $doc, 'id' => $id, 'zIndex' => array_key_exists('zIndex', $style)];
        }

        // Un href collegato con bind (parametro di sezione, campo) arriva al rendering: il pulsante non è finto.
        if ($type === 'nc/button' && !isset($node['bind']['settings.href'])) {
            $href = self::field($node, 'settings.href');
            $acc['buttons'][] = [
                'doc' => $doc,
                'id' => $id,
                'text' => self::text(self::field($node, 'content.text')),
                'href' => is_string($href) ? trim($href) : '',
            ];
        }

        $actions = ConsequentialActions::ofNode($node);
        if ($actions !== []) {
            $acc['forms'][] = ['doc' => $doc, 'id' => $id, 'actions' => $actions];
        }

        // Valori stringa di style, anche per breakpoint: le regole sui letterali li confrontano con i token.
        foreach ($style as $prop => $value) {
            foreach (is_array($value) ? $value : [$value] as $one) {
                if (is_string($prop) && is_string($one) && $one !== '') {
                    $acc['styles'][] = ['doc' => $doc, 'id' => $id, 'prop' => $prop, 'value' => $one];
                }
            }
        }

        // Una coppia per breakpoint in cui il colore o lo sfondo cambia; il breakpoint mancante eredita il base.
        $colors = self::responsive($style['color'] ?? null);
        $paints = $colors !== [] ? $this->paints($node) : null;
        if ($paints !== null) {
            $keys = array_values(array_unique([...array_keys($colors), ...array_keys($bgs)]));
            foreach ($keys as $breakpoint) {
                $fg = $colors[$breakpoint] ?? $colors['base'] ?? null;
                if ($fg === null) {
                    continue;
                }
                $fgHex = $this->hex($fg);
                $bgHex = $this->hex($bgs[$breakpoint] ?? $bgs['base'] ?? '$color.background');
                if ($fgHex !== null && $bgHex !== null) {
                    $acc['pairs'][] = [
                        'doc' => $doc,
                        'id' => $id,
                        'breakpoint' => (string) $breakpoint,
                        'fg' => $fgHex,
                        'bg' => $bgHex,
                        'large' => $level !== null && $level <= 2,
                        'graphic' => $paints === 'graphic',
                    ];
                }
            }
        }

        $children = $node['children'] ?? [];
        if (is_array($children)) {
            foreach ($children as $child) {
                if (is_array($child)) {
                    $this->walk($doc, $child, $bgs, $acc, $visible);
                }
            }
        }
    }

    /**
     * Cosa colora il colore del nodo: 'text' (testo, soglia 4.5:1 o 3:1),
     * 'graphic' (icona con etichetta, WCAG 1.4.11, 3:1), null (nulla da controllare:
     * icone decorative con aria-hidden, immagini, contenitori senza testo).
     *
     * @param array<mixed> $node
     */
    private function paints(array $node): ?string
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        if ($type === 'nc/icon') {
            // Un'etichetta collegata con bind arriva al rendering: l'icona diventa role="img", non decorativa.
            if (isset($node['bind']['content.label'])) {
                return 'graphic';
            }
            $label = self::field($node, 'content.label');

            return is_string($label) && trim($label) !== '' ? 'graphic' : null;
        }
        if (in_array($type, self::NON_TEXT, true)) {
            return null;
        }
        if (!in_array($type, self::BOXES, true)) {
            return 'text';
        }

        $found = null;
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            // Un figlio con un colore proprio risolvibile ha la sua coppia; inherit o currentColor ereditano questo.
            $childColor = is_array($child['style'] ?? null) ? self::baseValue($child['style']['color'] ?? null) : null;
            $own = $childColor !== null && $this->hex($childColor) !== null;
            $what = $own ? null : $this->paints($child);
            if ($what === 'text') {
                return 'text';
            }
            $found ??= $what;
        }

        return $found;
    }

    /**
     * Condizioni "is" di visibility.when; lista vuota se il nodo non ha visibilità.
     *
     * @param array<mixed> $node
     * @return list<string>
     */
    private static function whenConditions(array $node): array
    {
        $visibility = is_array($node['visibility'] ?? null) ? $node['visibility'] : [];
        $when = is_array($visibility['when'] ?? null) ? $visibility['when'] : [];

        $out = [];
        foreach ($when as $condition) {
            if (is_array($condition) && is_string($condition['is'] ?? null)) {
                $out[] = $condition['is'];
            }
        }

        return $out;
    }

    /**
     * Visibilità per breakpoint da visibility.breakpoints (booleani). Un breakpoint mancante è visibile
     * o eredita il valore del breakpoint più piccolo che lo precede.
     *
     * @param array<mixed> $node
     * @return array<string,bool>
     */
    private static function visibleMap(array $node): array
    {
        $visibility = is_array($node['visibility'] ?? null) ? $node['visibility'] : [];
        $flags = is_array($visibility['breakpoints'] ?? null) ? $visibility['breakpoints'] : [];

        $out = [];
        $current = true;
        foreach (self::BREAKPOINTS as $breakpoint) {
            if (is_bool($flags[$breakpoint] ?? null)) {
                $current = $flags[$breakpoint];
            }
            $out[$breakpoint] = $current;
        }

        return $out;
    }

    /** Valore base di una proprietà responsive (string o {base: ...}); null se assente. */
    private static function baseValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value) && is_string($value['base'] ?? null)) {
            return $value['base'];
        }

        return null;
    }

    /**
     * Valori stringa di una proprietà responsive, per breakpoint (il valore semplice è 'base'); mappa vuota se assente.
     *
     * @return array<string,string>
     */
    private static function responsive(mixed $value): array
    {
        if (is_string($value)) {
            return ['base' => $value];
        }

        $out = [];
        if (is_array($value)) {
            foreach ($value as $breakpoint => $one) {
                if (is_string($breakpoint) && is_string($one)) {
                    $out[$breakpoint] = $one;
                }
            }
        }

        return $out;
    }

    /** Colore hex, risolvendo i token; null se il valore non è un hex valido. */
    private function hex(string $value): ?string
    {
        $resolved = $this->tokens->isToken($value) ? $this->tokens->raw($value) : $value;

        return $resolved !== null && preg_match(self::HEX, $resolved) === 1 ? $resolved : null;
    }

    /**
     * Legge un percorso puntato ("settings.level") dentro il nodo; null se non esiste.
     *
     * @param array<mixed> $node
     */
    private static function field(array $node, mixed $path): mixed
    {
        if (!is_string($path) || $path === '') {
            return null;
        }

        $cur = $node;
        foreach (explode('.', $path) as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return null;
            }
            $cur = $cur[$key];
        }

        return $cur;
    }

    private static function text(mixed $raw): string
    {
        return is_string($raw) ? trim(wp_strip_all_tags($raw)) : '';
    }

    /**
     * aria-label dichiarato in content o settings; null se assente o vuoto.
     *
     * @param array<mixed> $node
     */
    private static function ariaLabel(array $node): ?string
    {
        foreach (['content', 'settings'] as $bucket) {
            $values = $node[$bucket] ?? null;
            if (!is_array($values)) {
                continue;
            }
            $label = $values['ariaLabel'] ?? null;
            if (is_string($label) && trim($label) !== '') {
                return trim($label);
            }
        }

        return null;
    }

    /**
     * La prima immagine non decorativa è l'LCP stimato: eager e alta priorità.
     * Le altre (e le decorative) sono differite.
     *
     * @param list<ImageEntry> $images
     * @return list<ImageEntry>
     */
    private static function assignImageRoles(array $images): array
    {
        $lcpAssigned = false;
        $out = [];
        foreach ($images as $image) {
            if (!$image['decorative'] && !$lcpAssigned) {
                $image['lcp'] = true;
                $image['lazy'] = false;
                $lcpAssigned = true;
            } else {
                $image['lcp'] = false;
                $image['lazy'] = true;
            }
            $out[] = $image;
        }

        return $out;
    }
}
