<?php

declare(strict_types=1);

namespace Nucleo\Abilities\Read;

use Nucleo\Abilities\AbilityDefinition;
use Nucleo\Layout\LayoutResolver;
use Nucleo\Layout\RequestContext;
use Nucleo\Runtime\Areas;
use Nucleo\Services;

/** nucleo/layout-explain: per un URL, il layout scelto in ciascuna area e la chiave che lo ha fatto scegliere. */
final class LayoutExplain implements AbilityDefinition
{
    private const AREAS = ['header', 'before-content', 'after-content', 'footer', 'single', 'archive', 'search', 'notfound', 'popup', 'product', 'product-archive'];
    /** Aree di WooCommerce: valgono solo dove le applica il runtime (pagina prodotto, Shop e archivi dei prodotti). */
    private const WOO_AREAS = ['product', 'product-archive'];

    public function name(): string
    {
        return 'nucleo/layout-explain';
    }

    public function args(): array
    {
        return [
            'label' => __('Explain layout', 'nucleo'),
            'description' => 'For a URL, shows for each area the resolved layout and the key that chose it (matched) with a readable rule, or null. If a layout is excluded for that URL, the area reports excluded with the layout and the rule (by). Areas product and product-archive exist only with WooCommerce, on a product and on the Shop or a product archive; elsewhere they are null. They win over single and archive (search also wins over archive): the losing area reports superseded with the winning area. applied is what the page really prints, area → layout id, after the page settings and document layout "none": read it before editing a layout. postId is the post of the URL (url_to_postid, 0 if none), keys are the request keys tried, conflicts are the priority conflicts of the map (as in layout-list).',
            'input_schema' => [
                'type' => 'object',
                'required' => ['url'],
                'properties' => [
                    'url' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2048],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'description' => 'For each area: {layout, matched, rule, excluded?, superseded?} or null. applied: object area → layout id printed by the runtime. Also url (as given), postId (integer), keys (list of the request keys tried) and conflicts (list, as in layout-list).',
            ],
            'meta' => [
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ];
    }

    /** @return array<string,mixed>|\WP_Error */
    public function execute(array $input): array|\WP_Error
    {
        $url = $input['url'] ?? null;
        if (!is_string($url) || $url === '') {
            return new \WP_Error('invalid_input', __('Invalid url field.', 'nucleo'));
        }

        $artifact = Services::store()->get('layouts');
        $resolver = new LayoutResolver(
            is_array($artifact['map'] ?? null) ? $artifact['map'] : [],
            is_array($artifact['exclude'] ?? null) ? $artifact['exclude'] : [],
        );
        $context = RequestContext::fromUrl($url);

        $out = ['url' => $url];
        foreach (self::AREAS as $area) {
            if (in_array($area, self::WOO_AREAS, true) && !Areas::applies($area, $context, class_exists('WooCommerce'))) {
                $out[$area] = null;
                continue;
            }
            $hit = $resolver->resolve($area, $context);
            if ($hit !== null) {
                $hit['rule'] = self::rule($hit['matched']);
            }
            $excluded = $resolver->excluded($area, $context);
            // Con esclusioni l'area riporta quali layout sono stati scartati e da quale regola.
            $out[$area] = $excluded === [] ? $hit : ($hit ?? ['layout' => null, 'matched' => null]) + ['excluded' => $excluded];
        }

        // Il runtime non stampa single sotto product, né archive sotto product-archive o search.
        foreach (['single' => ['product'], 'archive' => ['product-archive', 'search']] as $area => $winners) {
            foreach ($winners as $winner) {
                if (is_array($out[$area]) && is_array($out[$winner]) && ($out[$winner]['layout'] ?? null) !== null) {
                    $out[$area]['superseded'] = $winner;
                    break;
                }
            }
        }

        // Campi dopo le aree: i loro nomi non coincidono con nessuna area, quindi i consumer esistenti non cambiano.
        $out['postId'] = url_to_postid($url);
        // Esito del runtime (Areas::plan): impostazioni della pagina, layout none e precedenza delle aree.
        $applied = [];
        foreach (Areas::plan($resolver, $context, (int) $out['postId'], null, class_exists('WooCommerce')) as $area => $hit) {
            $applied[$area] = $hit['layout'] ?? null;
        }
        $out['applied'] = (object) $applied;
        $out['keys'] = $resolver->keys($context);
        $out['conflicts'] = is_array($artifact['conflicts'] ?? null) ? $artifact['conflicts'] : [];

        return $out;
    }

    /** Chiave della mappa in forma leggibile: "post:120" diventa "include post 120". */
    private static function rule(string $key): string
    {
        return 'include ' . ($key === 'site' ? 'entire site' : str_replace(':', ' ', $key));
    }

    public function permitted(array $input): bool
    {
        return current_user_can('edit_theme_options');
    }
}
