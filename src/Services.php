<?php

declare(strict_types=1);

namespace Nucleo;

defined('ABSPATH') || exit;

use InvalidArgumentException;
use Nucleo\Blocks\Registry;
use Nucleo\Compiler\Compiler;
use Nucleo\Compiler\DocumentService;
use Nucleo\Css\Breakpoints;
use Nucleo\Quality\PageComposer;
use Nucleo\Quality\QualityEngine;
use Nucleo\Schema\DocumentValidator;
use Nucleo\Schema\Normalizer;
use Nucleo\Schema\PatchEngine;
use Nucleo\Storage\ArtifactStore;
use Nucleo\Storage\ArtifactStoreFactory;
use Nucleo\Storage\DocumentRepository;
use Nucleo\Tokens\TokenSet;

/**
 * Istanze condivise del plugin, create al primo uso (pigre).
 * reset() le scarta: serve ai test e dopo un cambio di opzioni.
 */
final class Services
{
    private static ?Registry $registry = null;
    private static ?TokenSet $tokens = null;
    private static ?Breakpoints $breakpoints = null;
    private static ?Compiler $compiler = null;
    private static ?DocumentValidator $validator = null;
    private static ?Normalizer $normalizer = null;
    private static ?PatchEngine $patcher = null;
    private static ?PageComposer $composer = null;
    private static ?QualityEngine $quality = null;
    private static ?ArtifactStore $store = null;
    private static ?DocumentRepository $repository = null;
    private static ?DocumentService $documents = null;

    public static function reset(): void
    {
        self::$registry = null;
        self::$tokens = null;
        self::$breakpoints = null;
        self::$compiler = null;
        self::$validator = null;
        self::$normalizer = null;
        self::$patcher = null;
        self::$composer = null;
        self::$quality = null;
        self::$store = null;
        self::$repository = null;
        self::$documents = null;
    }

    public static function registry(): Registry
    {
        return self::$registry ??= self::loadRegistry();
    }

    public static function tokens(): TokenSet
    {
        return self::$tokens ??= self::loadTokens();
    }

    public static function breakpoints(): Breakpoints
    {
        return self::$breakpoints ??= new Breakpoints();
    }

    public static function compiler(): Compiler
    {
        return self::$compiler ??= new Compiler(self::registry(), self::tokens(), self::breakpoints());
    }

    public static function validator(): DocumentValidator
    {
        return self::$validator ??= new DocumentValidator(self::registry(), null, self::tokens());
    }

    public static function normalizer(): Normalizer
    {
        return self::$normalizer ??= new Normalizer(self::registry());
    }

    public static function patcher(): PatchEngine
    {
        return self::$patcher ??= new PatchEngine();
    }

    public static function composer(): PageComposer
    {
        return self::$composer ??= new PageComposer(self::registry(), self::tokens());
    }

    public static function quality(): QualityEngine
    {
        return self::$quality ??= QualityEngine::default(self::tokens());
    }

    public static function store(): ArtifactStore
    {
        return self::$store ??= ArtifactStoreFactory::create(\Nucleo\Storage\ArtifactStoreFactory::base());
    }

    public static function repository(): DocumentRepository
    {
        return self::$repository ??= new DocumentRepository();
    }

    public static function documents(): DocumentService
    {
        return self::$documents ??= new DocumentService(
            self::compiler(),
            self::validator(),
            self::normalizer(),
            self::patcher(),
            self::composer(),
            self::quality(),
            self::repository(),
        );
    }

    private static function loadRegistry(): Registry
    {
        $registry = Registry::fromDirectory(NUCLEO_DIR . 'blocks');
        do_action('nucleo/register_blocks', $registry);

        return $registry;
    }

    /** Opzione mancante o non valida: si ripiega sui default. */
    private static function loadTokens(): TokenSet
    {
        $stored = get_option('nucleo_tokens', []);
        try {
            return TokenSet::fromArray(is_array($stored) ? $stored : []);
        } catch (InvalidArgumentException) {
            return TokenSet::defaults();
        }
    }
}
