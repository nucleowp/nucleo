<?php

declare(strict_types=1);

namespace Nucleo\Compiler;

defined('ABSPATH') || exit;

/**
 * Lingua aperta durante una compilazione. Il filtro nucleo/language_scope la fornisce per un post:
 * il plugin multilingua attiva la lingua del post in enter() e ripristina quella della richiesta in leave().
 */
interface LanguageScope
{
    public function enter(): void;

    public function leave(): void;
}
