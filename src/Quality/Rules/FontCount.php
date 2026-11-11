<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

defined('ABSPATH') || exit;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;
use Nucleo\Tokens\FontImporter;

/** Più di due famiglie o più di quattro varianti (famiglia, peso, stile) installate. Un avviso per sito. */
final class FontCount implements Rule
{
    /** @param list<array{family:string,weight:int,style:string}>|null $faces null: i font installati */
    public function __construct(private readonly ?array $faces = null)
    {
    }

    public function id(): string
    {
        return 'perf.font.count';
    }

    public function severity(): Severity
    {
        return Severity::Warning;
    }

    public function check(ComposedPage $page): array
    {
        $faces = $this->faces ?? (function_exists('get_option') ? FontImporter::installed() : []);

        $families = [];
        $variants = [];
        foreach ($faces as $face) {
            $families[$face['family']] = true;
            $variants[$face['family'] . '|' . $face['weight'] . '|' . $face['style']] = true;
        }

        $familyCount = count($families);
        $variantCount = count($variants);
        if ($familyCount <= 2 && $variantCount <= 4) {
            return [];
        }

        return [new Issue(
            $this->id(),
            $this->severity(),
            sprintf(
                /* translators: 1: number of font families, 2: number of font variants. */
                __('The site uses %1$d font families and %2$d variants: the budget is 2 families and 4 variants.', 'nucleo'),
                $familyCount,
                $variantCount,
            ),
        )];
    }
}
