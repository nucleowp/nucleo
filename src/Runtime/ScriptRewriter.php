<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

defined('ABSPATH') || exit;

use Nucleo\Assets\SourceRules;

/**
 * Riscrive l'HTML in uscita: gli script stampati fuori dalla coda di WordPress che una regola sorgente riconosce
 * escono come tag inerti, e il loader li attiva al primo gesto o in idle. Il buffer parte solo se almeno una
 * regola sorgente vale per la pagina.
 */
final class ScriptRewriter
{
    /** Tipi di script che si riscrivono: JavaScript classico e modulo, o nessun tipo. */
    private const JS_TYPES = ['', 'text/javascript', 'application/javascript', 'module'];

    /** Byte iniziali in cui cercare il tag <html: un contenuto che non è una pagina torna com'è. */
    private const HTML_PROBE = 2000;

    /**
     * Da chiamare sull'hook wp, prima di ogni output. Legge le regole sorgente che valgono per la pagina
     * (AssetRuntime::sources); se ce ne sono apre il buffer con rewrite() come callback e accoda il loader.
     */
    public static function start(): void
    {
        if (is_feed() || is_robots() || is_trackback() || wp_doing_ajax() || is_customize_preview() || wp_is_serving_rest_request()) {
            return;
        }
        $rules = AssetRuntime::sources();
        if ($rules === []) {
            return;
        }
        ob_start(static fn (string $html): string => self::rewrite($html, $rules));
        add_action('wp_enqueue_scripts', [AssetRuntime::class, 'enqueueLoader']);
    }

    /**
     * Callback del buffer: l'HTML con i tag riconosciuti resi inerti. Qualunque altro contenuto torna identico.
     * Un solo WP_HTML_Tag_Processor su tutto l'HTML: commenti e textarea non danno tag script.
     *
     * @param list<array<string, mixed>> $rules voci di SourceRules::applicable()
     */
    public static function rewrite(string $html, array $rules): string
    {
        if ($rules === [] || stripos(substr($html, 0, self::HTML_PROBE), '<html') === false) {
            return $html;
        }

        $p = new \WP_HTML_Tag_Processor($html);
        $changed = false;
        while ($p->next_tag(['tag_name' => 'SCRIPT'])) {
            $changed = self::rewriteTag($p, $rules) || $changed;
        }

        return $changed ? $p->get_updated_html() : $html;
    }

    /**
     * Riscrive il tag script su cui è posizionato il processor, se una voce lo riconosce. True se lo ha cambiato.
     *
     * @param list<array<string, mixed>> $rules
     */
    private static function rewriteTag(\WP_HTML_Tag_Processor $p, array $rules): bool
    {
        $type = $p->get_attribute('type');
        $type = strtolower(trim(is_string($type) ? $type : ''));
        if (!in_array($type, self::JS_TYPES, true) || $p->get_attribute('data-nc-load') !== null) {
            return false;
        }

        // Script della coda di WordPress: i moduli li gestisce moduleAttributes, gli handle registrati li gestisce la coda.
        $id = $p->get_attribute('id');
        $id = is_string($id) ? $id : null;
        if ($id !== null) {
            $handle = SourceRules::queueHandle($id);
            if (str_ends_with($id, '-js-module') || ($handle !== null && wp_script_is($handle, 'registered'))) {
                return false;
            }
        }

        $module = $type === 'module';
        $srcAttr = $p->get_attribute('src');
        $src = $srcAttr === null ? null : (is_string($srcAttr) ? $srcAttr : '');
        // Un modulo inline non si riscrive: il suo codice non passa da un src da attivare.
        if ($src === null && $module) {
            return false;
        }

        $code = $src === null ? (string) $p->get_modifiable_text() : '';
        $rule = SourceRules::pick($rules, $src, $code);
        if ($rule === null) {
            return false;
        }
        $strategy = $rule['strategy'] ?? null;
        if (!is_string($strategy) || $strategy === '') {
            return false;
        }

        $p->set_attribute('type', 'text/plain');
        if ($src !== null) {
            $p->remove_attribute('src');
            $p->set_attribute('data-nc-src', $src);
        }
        $p->set_attribute('data-nc-load', $strategy);
        if ($module) {
            $p->set_attribute('data-nc-module', true);
        }
        if (is_string($rule['replay'] ?? null) && $rule['replay'] !== '') {
            $p->set_attribute('data-nc-replay', $rule['replay']);
        }

        return true;
    }
}
