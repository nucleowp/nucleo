<?php

declare(strict_types=1);

namespace Nucleo\Forms;

defined('ABSPATH') || exit;

/**
 * Configurazione di un nc/native-form (docs/15-dati-dinamici.md, livello 3), normalizzata.
 * Campo: {name, type, label, required, help, step, maxLength, min, max, decimals, placeholder, options, value, from, width, group}.
 * Azione: {type, ...configurazione dell'azione}.
 */
final class FormConfig
{
    private const NAME_RE = '/^[a-z][a-z0-9_]{0,31}$/D';
    private const MAX_FIELDS = 40;
    private const TYPES = [
        'text', 'email', 'tel', 'url', 'textarea', 'number', 'date',
        'select', 'radio', 'checkboxes', 'checkbox', 'consent', 'hidden', 'context',
    ];
    private const CONTEXT_FROM = ['post.id', 'post.title', 'post.url'];
    private const ACTION_TYPES = ['otp', 'save', 'email', 'webhook', 'create-post', 'component'];
    private const NUMBERINGS = ['none', 'letters', 'numbers', 'letters-box', 'numbers-box'];
    /** Chiave in content => chiave in labels. */
    private const LABEL_KEYS = [
        'submitLabel' => 'submit',
        'successMessage' => 'success',
        'errorMessage' => 'error',
        'codeLabel' => 'code',
        'nextLabel' => 'next',
        'backLabel' => 'back',
        'sentMessage' => 'sent',
        'sendCodeLabel' => 'sendCode',
        'verifyLabel' => 'verify',
        'otpFailedMessage' => 'otpFailed',
        'expiredMessage' => 'expired',
        'requiredMark' => 'requiredMark',
        'optionalMark' => 'optionalMark',
        'captchaMessage' => 'captcha',
    ];

    /**
     * @param list<array<string,mixed>> $fields
     * @param list<array{title:string}> $steps
     * @param list<array<string,mixed>> $actions
     * @param array<string,string> $labels submit, success, error, code, next, back, sent, sendCode, verify, otpFailed, expired (risolte, con default), requiredMark e optionalMark se impostate
     * @param string $redirect settings.redirect: home, page:<slug>, URL; '' se assente
     * @param string $groupNumbering settings.groupNumbering: none, letters, numbers, letters-box, numbers-box
     * @param bool $captcha settings.captcha: false toglie il captcha del sito da questo modulo
     */
    public function __construct(
        public readonly string $node,
        public readonly array $fields,
        public readonly array $steps,
        public readonly array $actions,
        public readonly array $labels,
        public readonly string $redirect = '',
        public readonly string $groupNumbering = 'none',
        public readonly bool $captcha = true,
    ) {
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $content
     */
    public static function fromNode(string $node, array $settings, array $content): self
    {
        return new self(
            $node,
            self::normalizeFields($settings['fields'] ?? null),
            self::normalizeSteps($settings['steps'] ?? null),
            self::normalizeActions($settings['actions'] ?? null),
            self::labelsFrom($content, true),
            self::str($settings['redirect'] ?? null),
            self::numbering($settings['groupNumbering'] ?? null),
            ($settings['captcha'] ?? true) !== false,
        );
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $node = $data['node'] ?? null;
        $fields = $data['fields'] ?? null;
        if (!is_string($node) || !is_array($fields) || !array_is_list($fields)) {
            return null;
        }
        $labels = $data['labels'] ?? null;

        return new self(
            $node,
            self::normalizeFields($fields),
            self::normalizeSteps($data['steps'] ?? null),
            self::normalizeActions($data['actions'] ?? null),
            self::labelsFrom(is_array($labels) ? $labels : [], false),
            self::str($data['redirect'] ?? null),
            self::numbering($data['groupNumbering'] ?? null),
            ($data['captcha'] ?? true) !== false,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $data = [
            'node' => $this->node,
            'fields' => $this->fields,
            'steps' => $this->steps,
            'actions' => $this->actions,
            'labels' => $this->labels,
        ];
        if ($this->redirect !== '') {
            $data['redirect'] = $this->redirect;
        }
        if ($this->groupNumbering !== 'none') {
            $data['groupNumbering'] = $this->groupNumbering;
        }
        if (!$this->captcha) {
            $data['captcha'] = false;
        }

        return $data;
    }

    /** @return array<string,mixed>|null */
    public function field(string $name): ?array
    {
        foreach ($this->fields as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Azione otp, se è la prima.
     * @return array<string,mixed>|null
     */
    public function otp(): ?array
    {
        $first = $this->actions[0] ?? null;

        return is_array($first) && ($first['type'] ?? null) === 'otp' ? $first : null;
    }

    /**
     * Passo dopo il quale si verifica il codice (azione otp con "step"), prima dell'invio finale.
     * Null senza azione otp, senza step o con uno step che non lascia passi dopo di sé.
     */
    public function otpStep(): ?int
    {
        $step = $this->otp()['step'] ?? null;

        return is_int($step) && $step >= 0 && $step < count($this->steps) - 1 ? $step : null;
    }

    /**
     * Campi validi: nome valido e unico, tipo noto, al massimo MAX_FIELDS. I campi non validi vengono scartati.
     * Width "half" solo se dichiarato, altrimenti "full"; group è una stringa vuota se assente.
     * @return list<array<string,mixed>>
     */
    private static function normalizeFields(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $fields = [];
        $names = [];
        foreach ($raw as $item) {
            if (count($fields) >= self::MAX_FIELDS) {
                break;
            }
            if (!is_array($item)) {
                continue;
            }
            $name = $item['name'] ?? null;
            $type = $item['type'] ?? null;
            if (!is_string($name) || preg_match(self::NAME_RE, $name) !== 1 || isset($names[$name])) {
                continue;
            }
            if (!is_string($type) || !in_array($type, self::TYPES, true)) {
                continue;
            }
            $from = self::str($item['from'] ?? null);
            if ($type === 'context' && !in_array($from, self::CONTEXT_FROM, true)) {
                continue;
            }
            $names[$name] = true;
            $fields[] = [
                'name' => $name,
                'type' => $type,
                'label' => self::str($item['label'] ?? null),
                'required' => ($item['required'] ?? false) === true,
                'help' => self::str($item['help'] ?? null),
                'step' => self::intMin($item['step'] ?? null, 0, 0),
                'maxLength' => self::intMin($item['maxLength'] ?? null, 1, $type === 'textarea' ? 2000 : 200),
                'min' => self::scalarStr($item['min'] ?? null),
                'max' => self::scalarStr($item['max'] ?? null),
                'decimals' => self::intMin($item['decimals'] ?? null, 0, 0),
                'placeholder' => self::str($item['placeholder'] ?? null),
                'options' => self::normalizeOptions($item['options'] ?? null),
                'value' => self::scalarStr($item['value'] ?? null),
                'from' => $type === 'context' ? $from : '',
                'width' => ($item['width'] ?? null) === 'half' ? 'half' : 'full',
                'group' => trim(self::str($item['group'] ?? null)),
            ];
        }

        return $fields;
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private static function normalizeOptions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $options = [];
        foreach ($raw as $option) {
            if (!is_array($option)) {
                continue;
            }
            $value = self::scalarStr($option['value'] ?? null);
            if ($value === '') {
                continue;
            }
            $label = self::str($option['label'] ?? null);
            $options[] = ['value' => $value, 'label' => $label !== '' ? $label : $value];
        }

        return $options;
    }

    /** @return list<array{title:string}> */
    private static function normalizeSteps(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $steps = [];
        foreach ($raw as $step) {
            if (is_array($step) && is_string($step['title'] ?? null)) {
                $steps[] = ['title' => $step['title']];
            }
        }

        return $steps;
    }

    /**
     * Azioni con tipo noto, copiate così come sono.
     * @return list<array<string,mixed>>
     */
    private static function normalizeActions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        /** @var list<array<string,mixed>> $actions */
        $actions = [];
        foreach ($raw as $action) {
            if (!is_array($action) || !is_string($action['type'] ?? null) || !in_array($action['type'], self::ACTION_TYPES, true)) {
                continue;
            }
            /** @var array<string,mixed> $copy */
            $copy = [];
            foreach ($action as $key => $value) {
                if (is_string($key)) {
                    $copy[$key] = $value;
                }
            }
            $actions[] = $copy;
        }

        return $actions;
    }

    /**
     * Etichette con default. Da content usa le chiavi di LABEL_KEYS, da labels le chiavi corte.
     * @param array<string,mixed> $source
     * @return array<string,string>
     */
    private static function labelsFrom(array $source, bool $fromContent): array
    {
        $labels = [
            'submit' => __('Send', 'nucleo'),
            'success' => __('Thank you.', 'nucleo'),
            'error' => __('Something went wrong. Try again.', 'nucleo'),
            'code' => __('Verification code', 'nucleo'),
            'next' => __('Next', 'nucleo'),
            'back' => __('Back', 'nucleo'),
            'sent' => __('We sent a code to your email.', 'nucleo'),
            'sendCode' => __('Get the code', 'nucleo'),
            'verify' => __('Verify', 'nucleo'),
            'otpFailed' => __('We could not send the code by email. Try again later.', 'nucleo'),
            'expired' => __('The code has expired. Request a new code: your answers stay in the form.', 'nucleo'),
            'captcha' => __('Complete the anti-spam check and try again.', 'nucleo'),
        ];
        foreach (self::LABEL_KEYS as $contentKey => $key) {
            $value = trim(self::str($source[$fromContent ? $contentKey : $key] ?? null));
            if ($value !== '') {
                $labels[$key] = $value;
            }
        }

        return $labels;
    }

    /** Numerazione dei gruppi di campi: none, letters, numbers o le varianti -box; ogni altro valore è none. */
    private static function numbering(mixed $raw): string
    {
        return is_string($raw) && in_array($raw, self::NUMBERINGS, true) ? $raw : 'none';
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function scalarStr(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : '';
    }

    private static function intMin(mixed $value, int $min, int $default): int
    {
        return is_int($value) && $value >= $min ? $value : $default;
    }
}
