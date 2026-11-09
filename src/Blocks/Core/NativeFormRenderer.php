<?php

declare(strict_types=1);

namespace Nucleo\Blocks\Core;

use Nucleo\Blocks\Node;
use Nucleo\Blocks\NodeValidator;
use Nucleo\Blocks\Renderer;
use Nucleo\Blocks\RenderContext;
use Nucleo\Content\ModelRepository;
use Nucleo\Extend\Components;
use Nucleo\Forms\FormConfig;
use Nucleo\Schema\ValidationError;

/**
 * nc/native-form (docs/15-dati-dinamici.md, livello 3). Al salvataggio compila configurazione ed etichette;
 * lo slot nc/native-form scrive nonce, contesto ed esito a ogni richiesta.
 */
final class NativeFormRenderer implements Renderer, NodeValidator
{
    private const TYPE = 'nc/native-form';
    private const OPTION_TYPES = ['select', 'radio', 'checkboxes'];
    private const STATUSES = ['draft', 'pending'];
    private const PLACEHOLDER = '/\{field:([^}]*)\}/';

    public function render(Node $node, RenderContext $ctx): string
    {
        $config = FormConfig::fromNode($node->id, $node->settings, $node->content);

        $classes = preg_split('/\s+/', trim($ctx->classes($node))) ?: [];
        $class = implode(' ', array_values(array_filter(
            $classes,
            static fn (string $c): bool => $c !== '' && $c !== 'nc-native-form',
        )));

        $data = [
            'node' => $node->id,
            'config' => $config->toArray(),
            'class' => $class,
        ];

        return $ctx->slot(self::TYPE, $data, '');
    }

    public function validateNode(array $node, string $path): array
    {
        $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
        $steps = self::items($settings['steps'] ?? null);
        $actions = self::items($settings['actions'] ?? null);
        $errors = [];

        /** nome campo => tipo, dei campi già letti */
        $types = [];
        /** nome campo => passo, dei campi già letti */
        $fieldSteps = [];
        foreach (self::items($settings['fields'] ?? null) as $i => $field) {
            if (!is_array($field)) {
                continue;
            }
            $base = "$path/settings/fields/$i";
            $name = $field['name'] ?? null;
            $type = $field['type'] ?? null;
            if (is_string($name) && isset($types[$name])) {
                $errors[] = $this->error(
                    "$base/name",
                    __('field name repeated', 'nucleo'),
                    $name,
                    __('use a unique name for each field', 'nucleo'),
                    'duplicate_field',
                );
            } elseif (is_string($name)) {
                $types[$name] = is_string($type) ? $type : '';
                $fieldSteps[$name] = is_int($field['step'] ?? null) ? $field['step'] : 0;
            }
            if (in_array($type, self::OPTION_TYPES, true) && self::items($field['options'] ?? null) === []) {
                $errors[] = $this->error(
                    "$base/options",
                    __('select, radio and checkboxes fields need options', 'nucleo'),
                    $field['options'] ?? null,
                    __('add at least one option with value and label', 'nucleo'),
                    'bad_options',
                );
            }
            $step = $field['step'] ?? 0;
            if (is_int($step) && (count($steps) === 0 ? $step > 0 : $step >= count($steps))) {
                $errors[] = $this->error(
                    "$base/step",
                    __('step not in the form steps', 'nucleo'),
                    $step,
                    __('use a step index below the number of steps, or 0 without steps', 'nucleo'),
                    'bad_step',
                );
            }
        }

        /** campi verificati da un'azione otp valida: prima azione, campo email dichiarato */
        $verified = [];
        $first = $actions[0] ?? null;
        if (
            is_array($first) && ($first['type'] ?? null) === 'otp' && is_string($first['field'] ?? null)
            && ($types[$first['field']] ?? null) === 'email'
        ) {
            $verified[$first['field']] = true;
        }

        foreach ($actions as $i => $action) {
            if (!is_array($action)) {
                continue;
            }
            $base = "$path/settings/actions/$i";
            $type = $action['type'] ?? null;
            /** @var list<array{string,mixed}> $texts percorso e testo da cercare con {field:x} */
            $texts = [];

            if ($type === 'otp') {
                if ($i !== 0) {
                    $errors[] = $this->error(
                        "$base/type",
                        __('the otp action must be the first action', 'nucleo'),
                        $i,
                        __('move the otp action to the first position', 'nucleo'),
                        'otp_first',
                    );
                }
                $field = $action['field'] ?? null;
                if (!is_string($field) || !isset($types[$field])) {
                    $errors[] = $this->unknownField("$base/field", $field);
                } elseif ($types[$field] !== 'email') {
                    $errors[] = $this->error(
                        "$base/field",
                        __('the otp field must be an email field', 'nucleo'),
                        $field,
                        __('use a field of type email', 'nucleo'),
                        'otp_first',
                    );
                }
                // Passo "step": il codice si verifica uscendo da quel passo; serve almeno un passo dopo, e il campo email non va dopo.
                if (array_key_exists('step', $action) && $action['step'] !== null) {
                    $otpStep = $action['step'];
                    $fieldStep = is_string($field) ? ($fieldSteps[$field] ?? 0) : 0;
                    if (!is_int($otpStep) || $otpStep < 0 || $otpStep > count($steps) - 2 || $fieldStep > $otpStep) {
                        $errors[] = $this->error(
                            "$base/step",
                            __('otp step does not match the form steps', 'nucleo'),
                            $otpStep,
                            __('use a step before the last one, not before the step of the email field', 'nucleo'),
                            'otp_step',
                        );
                    }
                }
                $texts = [["$base/subject", $action['subject'] ?? null], ["$base/body", $action['body'] ?? null]];
            } elseif ($type === 'save') {
                // Nessun parametro da controllare.
            } elseif ($type === 'email') {
                foreach (['to', 'replyTo'] as $key) {
                    $target = $action[$key] ?? null;
                    if (is_string($target) && str_starts_with($target, 'field:') && !isset($types[substr($target, 6)])) {
                        $errors[] = $this->unknownField("$base/$key", $target);
                    } elseif ($key === 'to' && is_string($target) && str_starts_with($target, 'field:') && !isset($verified[substr($target, 6)])) {
                        // Un invio a un indirizzo non confermato dall'utente: serve un'azione otp sullo stesso campo.
                        $errors[] = $this->error(
                            "$base/to",
                            __('recipient field not verified', 'nucleo'),
                            $target,
                            __('add an otp action on the same email field', 'nucleo'),
                            'unverified_recipient',
                        );
                    }
                }
                $texts = [["$base/subject", $action['subject'] ?? null], ["$base/body", $action['body'] ?? null]];
            } elseif ($type === 'create-post') {
                if (!in_array($action['postType'] ?? null, self::modelPostTypes(), true)) {
                    $errors[] = $this->error(
                        "$base/postType",
                        __('post type not in the model', 'nucleo'),
                        $action['postType'] ?? null,
                        __('use a post type of the model', 'nucleo'),
                        'unknown_post_type',
                    );
                }
                if (!in_array($action['status'] ?? null, self::STATUSES, true)) {
                    $errors[] = $this->error(
                        "$base/status",
                        __('status not allowed for create-post', 'nucleo'),
                        $action['status'] ?? null,
                        __('use draft or pending', 'nucleo'),
                        'bad_status',
                    );
                }
                $texts = [["$base/title", $action['title'] ?? null]];
                foreach (self::pairs($action['fields'] ?? null) as [$key, $value]) {
                    $texts[] = ["$base/fields/$key", $value];
                }
            } elseif ($type === 'webhook') {
                $url = $action['url'] ?? null;
                if (!is_string($url) || !str_starts_with(strtolower($url), 'https://')) {
                    $errors[] = $this->error(
                        "$base/url",
                        __('webhook url must use https', 'nucleo'),
                        $url,
                        __('use an https address', 'nucleo'),
                        'unsafe_url',
                    );
                }
            } elseif ($type === 'component') {
                $name = is_string($action['name'] ?? null) ? $action['name'] : '';
                if (Components::instance()->action($name) === null) {
                    $errors[] = $this->error(
                        "$base/name",
                        __('the component action is not registered', 'nucleo'),
                        $action['name'] ?? null,
                        __('use a component action registered by a plugin', 'nucleo'),
                        'unknown_component',
                    );
                }
                foreach (self::pairs($action['input'] ?? null) as [$key, $value]) {
                    $texts[] = ["$base/input/$key", $value];
                }
            }

            foreach ($texts as [$at, $text]) {
                foreach (self::placeholders($text) as $name) {
                    if (!isset($types[$name])) {
                        $errors[] = $this->unknownField($at, $name);
                    }
                }
            }
        }

        return $errors;
    }

    private function unknownField(string $path, mixed $value): ValidationError
    {
        return $this->error(
            $path,
            __('field not declared in the form', 'nucleo'),
            $value,
            __('use the name of a field of the form', 'nucleo'),
            'unknown_field',
        );
    }

    private function error(string $path, string $message, mixed $value, string $hint, string $code): ValidationError
    {
        return new ValidationError($path, $message, $value, [], $hint, $code);
    }

    /**
     * Tipi di contenuto del modello, come slug.
     * @return list<string>
     */
    private static function modelPostTypes(): array
    {
        $slugs = [];
        foreach (self::items(ModelRepository::model()['postTypes'] ?? null) as $entry) {
            $slug = is_string($entry) ? $entry : (is_array($entry) ? ($entry['slug'] ?? null) : null);
            if (is_string($slug)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Nomi dei segnaposto {field:x} nel testo.
     * @return list<string>
     */
    private static function placeholders(mixed $text): array
    {
        if (!is_string($text) || preg_match_all(self::PLACEHOLDER, $text, $matches) === false) {
            return [];
        }

        return $matches[1];
    }

    /**
     * Coppie chiave => valore di una mappa, o nessuna se non è una mappa.
     * @return list<array{string,mixed}>
     */
    private static function pairs(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $pairs = [];
        foreach ($value as $key => $item) {
            $pairs[] = [(string) $key, $item];
        }

        return $pairs;
    }

    /** @return list<mixed> */
    private static function items(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
