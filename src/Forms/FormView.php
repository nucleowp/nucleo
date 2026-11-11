<?php

declare(strict_types=1);

namespace Nucleo\Forms;

defined('ABSPATH') || exit;

/** Markup del modulo nativo. Dati dello slot: {node, config: FormConfig::toArray(), class}. */
final class FormView
{
    /** Lunghezza massima della parte decimale per il passo dei campi number. */
    private const MAX_DECIMALS = 15;

    /**
     * @param array<string,mixed> $data
     * @param array{status:string, ticket:string} $result esito letto da $_GET (nc_status, nc_ticket) per l'invio senza script
     */
    public static function render(array $data, FormConfig $config, int $doc, int $contextId, array $result): string
    {
        $node = $config->node;
        $action = rest_url('nucleo/v1/form/' . $doc . '/' . $node);
        $status = is_string($result['status'] ?? null) ? $result['status'] : '';
        $ticket = is_string($result['ticket'] ?? null) ? $result['ticket'] : '';
        $otp = $config->otp();
        $otpStep = $config->otpStep();
        $otpState = $status === 'otp' && $otp !== null;
        $labels = $config->labels;
        $count = max(1, count($config->steps));
        $class = is_string($data['class'] ?? null) ? $data['class'] : '';

        $message = match (true) {
            $status === 'done' => $labels['success'],
            $status === 'error' => $labels['error'],
            $otpState => $labels['sent'],
            default => '',
        };

        // Con verifica a metà modulo lo script chiede il codice al passo indicato e riempie il campo della prova.
        $otpAttrs = $otpStep !== null && $otp !== null
            ? ' data-otp-step="' . esc_attr((string) $otpStep) . '"'
                . ' data-otp-field="' . esc_attr(is_string($otp['field'] ?? null) ? $otp['field'] : '') . '"'
                . ' data-send-label="' . esc_attr($labels['sendCode']) . '"'
                . ' data-verify-label="' . esc_attr($labels['verify']) . '"'
            : '';

        // Con groupNumbering il CSS numera le legend dei gruppi di campi.
        $numbering = $config->groupNumbering !== 'none'
            ? ' data-numbering="' . esc_attr($config->groupNumbering) . '"'
            : '';

        $html = '<form class="' . esc_attr(trim('nc-native-form ' . $class)) . '" method="post"'
            . ' action="' . esc_url($action) . '"'
            . ' data-nonce-url="' . esc_url($action . '/nonce') . '"'
            . ' data-steps="' . $count . '"'
            . ' data-error-label="' . esc_attr($labels['error']) . '"'
            . $numbering
            . $otpAttrs . '>';
        $html .= '<p class="nc-native-form__message" role="status" aria-live="polite">' . esc_html($message) . '</p>';

        if ($status === 'done') {
            return $html . '</form>';
        }

        $html .= '<input type="hidden" name="_nc_nonce" value="' . esc_attr(FormRest::nonce($doc, $node)) . '">';
        $html .= '<input type="hidden" name="_nc_ctx" value="' . esc_attr((string) $contextId) . '">';
        $html .= '<input type="hidden" name="_nc_back" value="' . esc_url(self::backUrl()) . '">';
        if ($otpStep !== null) {
            $html .= '<input type="hidden" name="_nc_proof" value="">';
        }
        $html .= self::honeypot($node);

        // Nello stato otp né campi né passaggi: solo il blocco del codice, il messaggio e l'invio.
        if (!$otpState) {
            $html .= self::steps($config, $count, false);
            if ($config->captcha) {
                $html .= Captcha::widget($node);
            }
            $html .= '<div class="nc-native-form__nav">'
                . '<button type="button" data-action="back" hidden>' . esc_html($labels['back']) . '</button>'
                . '<button type="button" data-action="next" hidden>' . esc_html($labels['next']) . '</button>'
                . '<button type="submit">' . esc_html($labels['submit']) . '</button>'
                . '</div>';
        } else {
            $html .= '<div class="nc-native-form__nav"><button type="submit">' . esc_html($labels['submit']) . '</button></div>';
        }

        if ($otp !== null) {
            $html .= self::otp($node, $labels['code'], $otpState, $otpState ? $ticket : '');
        }

        return $html . '</form>';
    }

    /** Campo di trappola per i bot: fuori schermo via CSS, non nascosto con display:none. */
    private static function honeypot(string $node): string
    {
        $id = 'nc-' . $node . '-_nc_hp';

        return '<div class="nc-native-form__hp" aria-hidden="true">'
            . '<label for="' . esc_attr($id) . '">' . esc_html(__('Leave this field empty', 'nucleo')) . '</label>'
            . '<input type="text" name="_nc_hp" id="' . esc_attr($id) . '" tabindex="-1" autocomplete="off" value="">'
            . '</div>';
    }

    /**
     * Passaggi: un fieldset per passo. Senza passaggi, un solo fieldset senza legend.
     * Un campo con passo oltre il numero dei passaggi va all'ultimo.
     */
    private static function steps(FormConfig $config, int $count, bool $hidden): string
    {
        $groups = array_fill(0, $count, []);
        foreach ($config->fields as $field) {
            if ($field['type'] === 'context') {
                continue;
            }
            $index = min($field['step'], $count - 1);
            $groups[$index][] = $field;
        }

        if ($config->steps === []) {
            return self::step(0, null, $hidden, self::fields($groups[0], $config->node, $config->labels));
        }

        $html = '';
        foreach ($config->steps as $index => $step) {
            $html .= self::step($index, $step['title'], $hidden, self::fields($groups[$index], $config->node, $config->labels));
        }

        return $html;
    }

    private static function step(int $index, ?string $title, bool $hidden, string $fields): string
    {
        $legend = $title === null ? '' : '<legend>' . esc_html($title) . '</legend>';

        return '<fieldset class="nc-native-form__step" data-step="' . $index . '"' . ($hidden ? ' hidden' : '') . '>'
            . $legend . $fields . '</fieldset>';
    }

    /**
     * Campi di un passo. Campi consecutivi con lo stesso group stanno in un fieldset con legend = group;
     * senza group nessun fieldset.
     * @param list<array<string,mixed>> $fields
     * @param array<string,string> $labels
     */
    private static function fields(array $fields, string $node, array $labels): string
    {
        $html = '';
        $open = '';
        $inner = '';
        foreach ($fields as $field) {
            if ($field['group'] !== $open) {
                $html .= $open === '' ? $inner : self::fieldGroup($open, $inner);
                $inner = '';
                $open = $field['group'];
            }
            $inner .= self::field($field, $node, $labels);
        }

        return $html . ($open === '' ? $inner : self::fieldGroup($open, $inner));
    }

    private static function fieldGroup(string $group, string $fields): string
    {
        return '<fieldset class="nc-native-form__group"><legend>' . esc_html($group) . '</legend>' . $fields . '</fieldset>';
    }

    /**
     * @param array<string,mixed> $field
     * @param array<string,string> $labels
     */
    private static function field(array $field, string $node, array $labels): string
    {
        $name = $field['name'];
        $type = $field['type'];
        if ($type === 'context') {
            return '';
        }
        if ($type === 'hidden') {
            return '<div class="nc-native-form__field" data-field="' . esc_attr($name) . '">'
                . '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($field['value']) . '">'
                . '</div>';
        }

        $id = 'nc-' . $node . '-' . $name;
        $errorId = $id . '-error';
        $help = '';
        $describedBy = $errorId;
        if ($field['help'] !== '') {
            $helpId = $id . '-help';
            $help = '<p class="nc-native-form__help" id="' . esc_attr($helpId) . '">' . esc_html($field['help']) . '</p>';
            $describedBy = $helpId . ' ' . $errorId;
        }
        $error = '<p class="nc-native-form__error" id="' . esc_attr($errorId) . '" hidden></p>';
        $attrs = ' id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"'
            . ' aria-describedby="' . esc_attr($describedBy) . '"'
            . ($field['required'] ? ' required' : '');
        $label = '<label for="' . esc_attr($id) . '">' . esc_html($field['label']) . self::mark($field, $labels) . '</label>';
        $placeholder = $field['placeholder'] !== '' ? ' placeholder="' . esc_attr($field['placeholder']) . '"' : '';

        $control = match ($type) {
            'text', 'email', 'tel', 'url' => $label . '<input type="' . $type . '"' . $attrs
                . ' maxlength="' . $field['maxLength'] . '"' . $placeholder . '>',
            'textarea' => $label . '<textarea' . $attrs . ' maxlength="' . $field['maxLength'] . '"'
                . $placeholder . '></textarea>',
            'number' => $label . '<input type="number"' . $attrs . self::range($field)
                . ' step="' . esc_attr(self::numberStep($field['decimals'])) . '">',
            'date' => $label . '<input type="date"' . $attrs . self::range($field) . '>',
            'select' => $label . '<select' . $attrs . '><option value=""></option>'
                . self::options($field['options']) . '</select>',
            'radio', 'checkboxes' => self::group($field, $node, $describedBy, $labels),
            'checkbox', 'consent' => '<div class="nc-native-form__check"><input type="checkbox"' . $attrs
                . ' value="1">' . $label . '</div>',
            default => '',
        };

        $width = $field['width'] === 'half' ? ' nc-native-form__field--half' : '';

        return '<div class="nc-native-form__field' . $width . '" data-field="' . esc_attr($name) . '">'
            . $control . $help . $error . '</div>';
    }

    /**
     * Marchio dopo l'etichetta: obbligatorio o facoltativo, dalle etichette. Vuoto se la marca non è impostata
     * e per i tipi checkbox, consent, hidden e context.
     * @param array<string,mixed> $field
     * @param array<string,string> $labels
     */
    private static function mark(array $field, array $labels): string
    {
        if (in_array($field['type'], ['checkbox', 'consent', 'hidden', 'context'], true)) {
            return '';
        }
        if ($field['required']) {
            $mark = $labels['requiredMark'] ?? '';

            return $mark === '' ? '' : '<span class="nc-native-form__mark" aria-hidden="true">' . esc_html($mark) . '</span>';
        }
        $mark = $labels['optionalMark'] ?? '';

        return $mark === '' ? '' : '<span class="nc-native-form__optional">' . esc_html($mark) . '</span>';
    }

    /**
     * Gruppo di scelte in fieldset con legend. Il nome dei checkboxes è una lista (name[]).
     * @param array<string,mixed> $field
     * @param array<string,string> $labels
     */
    private static function group(array $field, string $node, string $describedBy, array $labels): string
    {
        $isRadio = $field['type'] === 'radio';
        $name = $field['name'] . ($isRadio ? '' : '[]');
        $base = 'nc-' . $node . '-' . $field['name'];
        $html = '<fieldset class="nc-native-form__choice" aria-describedby="' . esc_attr($describedBy) . '">'
            . '<legend>' . esc_html($field['label']) . self::mark($field, $labels) . '</legend>';
        foreach ($field['options'] as $i => $option) {
            $html .= '<label><input type="' . ($isRadio ? 'radio' : 'checkbox') . '"'
                . ' id="' . esc_attr($base . '-' . $i) . '"'
                . ' name="' . esc_attr($name) . '"'
                . ' value="' . esc_attr($option['value']) . '"'
                . ($isRadio && $field['required'] ? ' required' : '')
                . '> ' . esc_html($option['label']) . '</label>';
        }

        return $html . '</fieldset>';
    }

    /** @param list<array{value:string,label:string}> $options */
    private static function options(array $options): string
    {
        $html = '';
        foreach ($options as $option) {
            $html .= '<option value="' . esc_attr($option['value']) . '">' . esc_html($option['label']) . '</option>';
        }

        return $html;
    }

    /** @param array<string,mixed> $field */
    private static function range(array $field): string
    {
        return ($field['min'] !== '' ? ' min="' . esc_attr($field['min']) . '"' : '')
            . ($field['max'] !== '' ? ' max="' . esc_attr($field['max']) . '"' : '');
    }

    /** Passo del campo number: 1 con zero decimali, altrimenti 10^-decimals. */
    private static function numberStep(int $decimals): string
    {
        $decimals = min($decimals, self::MAX_DECIMALS);

        return $decimals <= 0 ? '1' : '0.' . str_repeat('0', $decimals - 1) . '1';
    }

    /** Blocco del codice OTP: nascosto se non è lo stato otp, disabilitato per non inviare un codice vuoto. */
    private static function otp(string $node, string $label, bool $visible, string $ticket): string
    {
        $id = 'nc-' . $node . '-_nc_code';

        return '<div class="nc-native-form__otp"' . ($visible ? '' : ' hidden') . '>'
            . '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>'
            . '<input type="text" name="_nc_code" id="' . esc_attr($id) . '"'
            . ' inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required'
            . ($visible ? '' : ' disabled') . '>'
            . '<input type="hidden" name="_nc_ticket" value="' . esc_attr($ticket) . '">'
            . '</div>';
    }

    /** URL della pagina corrente senza i parametri di esito, per l'invio senza script. */
    private static function backUrl(): string
    {
        $uri = sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '/')));

        // REQUEST_URI contiene già la sottocartella del sito: dall'URL di casa servono solo schema, host e porta.
        $home = wp_parse_url(home_url('/'));
        $origin = ($home['scheme'] ?? 'http') . '://' . ($home['host'] ?? '') . (isset($home['port']) ? ':' . $home['port'] : '');

        return esc_url_raw(remove_query_arg(['nc_form', 'nc_status', 'nc_ticket'], $origin . '/' . ltrim($uri, '/')));
    }
}
