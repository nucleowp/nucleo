<?php

declare(strict_types=1);

namespace Nucleo\Extend;

/**
 * Registro delle sorgenti dati e delle azioni dei plugin del sito.
 * La prima chiamata a instance() esegue l'hook nucleo/register_components.
 */
final class Components
{
    public const NAME = '/^[a-z0-9-]{1,40}\/[a-z0-9-]{1,40}$/D';

    private static ?self $instance = null;

    /** @var array<string,DataSource> */
    private array $dataSources = [];

    /** @var array<string,Action> */
    private array $actions = [];

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            do_action('nucleo/register_components', self::$instance);
        }

        return self::$instance;
    }

    /** Solo per i test: svuota il registro. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /** Nome non valido o già usato: scartato con un avviso nel log. */
    public function add(DataSource|Action $component): void
    {
        $name = $component->name();
        if (preg_match(self::NAME, $name) !== 1 || isset($this->dataSources[$name]) || isset($this->actions[$name])) {
            _doing_it_wrong(
                __METHOD__,
                esc_html(sprintf(/* translators: %s: component name */ __('Invalid or duplicate component name: %s', 'nucleo'), $name)),
                '1.0.0',
            );

            return;
        }
        if ($component instanceof DataSource) {
            $this->dataSources[$name] = $component;
        } else {
            $this->actions[$name] = $component;
        }
    }

    public function dataSource(string $name): ?DataSource
    {
        return $this->dataSources[$name] ?? null;
    }

    public function action(string $name): ?Action
    {
        return $this->actions[$name] ?? null;
    }

    /**
     * Nomi, etichette e schemi, mai il codice.
     * @return array{dataSources: list<array{name:string,label:string,schema:array<string,mixed>}>, actions: list<array{name:string,label:string,inputSchema:array<string,mixed>}>}
     */
    public function describe(): array
    {
        $sources = [];
        foreach ($this->dataSources as $name => $source) {
            $sources[] = ['name' => $name, 'label' => $source->label(), 'schema' => $source->schema()];
        }
        $actions = [];
        foreach ($this->actions as $name => $action) {
            $actions[] = ['name' => $name, 'label' => $action->label(), 'inputSchema' => $action->inputSchema()];
        }

        return ['dataSources' => $sources, 'actions' => $actions];
    }
}
