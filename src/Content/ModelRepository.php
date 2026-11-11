<?php

declare(strict_types=1);

namespace Nucleo\Content;

defined('ABSPATH') || exit;

use Nucleo\Services;

/** Modello dei contenuti salvato: opzione nucleo_content_model e artefatto content-model (docs/contracts/content-model.md). */
final class ModelRepository
{
    public const OPTION = 'nucleo_content_model';

    public const ARTIFACT = 'content-model';

    /** Chiavi del modello che model-set può omettere: una chiave assente resta come è salvata. */
    public const PARTS = ['postTypes', 'taxonomies', 'fieldGroups'];

    /**
     * Modello salvato. Ha sempre le chiavi schema, postTypes, taxonomies, fieldGroups.
     *
     * @return array<string,mixed>
     */
    public static function model(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge(
            ['schema' => 1, 'postTypes' => [], 'taxonomies' => [], 'fieldGroups' => []],
            is_array($stored) ? $stored : [],
        );
    }

    /**
     * Scrive l'opzione (senza autoload) e l'artefatto nello store, da cui legge ModelRegistrar.
     *
     * @param array<string,mixed> $model
     */
    public static function save(array $model): void
    {
        update_option(self::OPTION, $model, false);
        Services::store()->put(self::ARTIFACT, $model);
        do_action('nucleo/model_saved', $model);
    }

    /** Riscrive l'artefatto dal database (StoreMaintenance::rebuild). Senza modello salvato cancella l'artefatto. */
    public static function publish(): void
    {
        if (is_array(get_option(self::OPTION, null))) {
            Services::store()->put(self::ARTIFACT, self::model());
        } else {
            Services::store()->delete(self::ARTIFACT);
        }
    }

    /**
     * Modello da salvare con model-set: le chiavi di PARTS assenti nell'input restano come sono salvate.
     *
     * @param array<string,mixed> $input
     * @return array{model: array<string,mixed>, kept: list<string>}
     */
    public static function merge(array $input): array
    {
        $saved = self::model();
        $kept = [];
        foreach (self::PARTS as $part) {
            if (!array_key_exists($part, $input)) {
                $input[$part] = $saved[$part];
                if ($saved[$part] !== []) {
                    $kept[] = $part;
                }
            }
        }
        $input += ['schema' => $saved['schema']];

        return ['model' => $input, 'kept' => $kept];
    }

    /**
     * Errori di una parte non inviata (kept): il messaggio dice che la parte è quella salvata e va inviata per cambiarla.
     *
     * @param list<ModelError> $errors
     * @param list<string> $kept
     * @return list<ModelError>
     */
    public static function explainKept(array $errors, array $kept): array
    {
        return array_map(static function (ModelError $error) use ($kept): ModelError {
            $part = explode('/', ltrim($error->path, '/'))[0];
            if (!in_array($part, $kept, true)) {
                return $error;
            }

            return new ModelError($error->code, $error->path, sprintf(
                /* translators: 1: error message, 2: part of the model (postTypes, taxonomies, fieldGroups) */
                __('%1$s (in the saved %2$s, not sent in this call: send %2$s too, without the entries that refer to what you remove)', 'nucleo'),
                $error->message,
                $part,
            ));
        }, $errors);
    }

    /**
     * Errori del modello secondo ModelValidator. Gli slug già presenti nel modello salvato, o registrati da ModelRegistrar
     * in questa richiesta, non contano come "registrati da altri": altrimenti un tipo creato dal modello diventerebbe
     * reserved_slug al salvataggio successivo.
     *
     * @param array<string,mixed> $model
     * @return list<ModelError>
     */
    public static function validate(array $model): array
    {
        $saved = self::model();
        $registered = static function (string $kind, string $slug) use ($saved): bool {
            $own = $kind === 'postType' ? $saved['postTypes'] : $saved['taxonomies'];
            if (in_array($slug, array_column($own, 'slug'), true) || ModelRegistrar::registered($kind, $slug)) {
                return false;
            }

            return $kind === 'postType' ? post_type_exists($slug) : taxonomy_exists($slug);
        };

        return (new ModelValidator($registered))->validate($model);
    }

    /**
     * Quanti contenuti, termini e valori salvati usa il modello. Restituisce solo le voci con valori > 0.
     * I contenuti non contano auto-draft e cestino.
     *
     * @param array<string,mixed> $model
     * @return array{postTypes: array<string,int>, taxonomies: array<string,int>, fields: array<string,int>}
     */
    public static function usage(array $model): array
    {
        global $wpdb;

        $usage = ['postTypes' => [], 'taxonomies' => [], 'fields' => []];

        foreach ($model['postTypes'] ?? [] as $postType) {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'trash')",
                $postType['slug'],
            ));
            if ($count > 0) {
                $usage['postTypes'][$postType['slug']] = $count;
            }
        }

        foreach ($model['taxonomies'] ?? [] as $taxonomy) {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
                $taxonomy['slug'],
            ));
            if ($count > 0) {
                $usage['taxonomies'][$taxonomy['slug']] = $count;
            }
        }

        foreach ($model['fieldGroups'] ?? [] as $group) {
            $location = $group['location'];
            foreach ($group['fields'] ?? [] as $field) {
                if (isset($location['postType'])) {
                    $count = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value <> ''",
                        $location['postType'],
                        $field['key'],
                    ));
                } else {
                    $count = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(DISTINCT tm.term_id) FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tt.taxonomy = %s AND tm.meta_key = %s AND tm.meta_value <> ''",
                        $location['taxonomy'],
                        $field['key'],
                    ));
                }
                if ($count > 0) {
                    $usage['fields'][$group['id'] . '/' . $field['key']] = $count;
                }
            }
        }

        return $usage;
    }
}
