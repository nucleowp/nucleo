<?php

declare(strict_types=1);

namespace Nucleo\Quality\Rules;

defined('ABSPATH') || exit;

use Nucleo\Quality\ComposedPage;
use Nucleo\Quality\Issue;
use Nucleo\Quality\Rule;
use Nucleo\Quality\Severity;
use Nucleo\Tokens\TokenMatcher;

/** Valore letterale di stile uguale a un token: va scritto come riferimento al token. */
final class TokenLiteral implements Rule
{
    public function __construct(private readonly TokenMatcher $matcher)
    {
    }

    public function id(): string
    {
        return 'style.token.literal';
    }

    public function severity(): Severity
    {
        return Severity::Error;
    }

    public function check(ComposedPage $page): array
    {
        $issues = [];
        $seen = [];
        foreach ($page->styles as $style) {
            $token = $this->matcher->match($style['prop'], $style['value']);
            if ($token === null) {
                continue;
            }

            // Una sola issue per nodo, proprietà e valore: i breakpoint ripetono la stessa voce.
            $key = implode("\0", [$style['doc'], $style['id'], $style['prop'], $style['value']]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $issues[] = new Issue(
                $this->id(),
                $this->severity(),
                sprintf(
                    /* translators: 1: style value, 2: style property, 3: token such as $color.primary. */
                    __('The value %1$s of %2$s equals the token %3$s: write %3$s.', 'nucleo'),
                    $style['value'],
                    $style['prop'],
                    $token,
                ),
                [['doc' => $style['doc'], 'id' => $style['id']]],
            );
        }

        return $issues;
    }
}
