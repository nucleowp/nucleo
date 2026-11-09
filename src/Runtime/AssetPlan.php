<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

/**
 * Unisce gli asset delle parti in ordine: stili e script senza duplicati per src,
 * con la prima occorrenza vinta. Gli script "eager" finiscono in eager (solo src),
 * gli altri in lazy (voce completa).
 */
final class AssetPlan
{
    /**
     * @param list<array{styles?:list<string>,scripts?:list<array{type:string,src:string,strategy:string}>}> $parts
     * @return array{styles:list<string>,eager:list<string>,lazy:list<array{type:string,src:string,strategy:string}>}
     */
    public function plan(array $parts): array
    {
        $styles = [];
        $eager = [];
        $lazy = [];
        $seen = [];

        foreach ($parts as $part) {
            foreach ($part['styles'] ?? [] as $style) {
                $styles[] = $style;
            }
            foreach ($part['scripts'] ?? [] as $script) {
                if (isset($seen[$script['src']])) {
                    continue;
                }
                $seen[$script['src']] = true;
                if ($script['strategy'] === 'eager') {
                    $eager[] = $script['src'];
                } else {
                    $lazy[] = $script;
                }
            }
        }

        return [
            'styles' => array_values(array_unique($styles)),
            'eager' => $eager,
            'lazy' => $lazy,
        ];
    }
}
