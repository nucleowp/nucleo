<?php

declare(strict_types=1);

namespace Nucleo\Css;

defined('ABSPATH') || exit;

/**
 * Chiavi di stile per le parti interne dei blocchi interattivi. Ogni chiave scrive una variabile CSS sul nodo;
 * il CSS del blocco la legge sui suoi elementi, con un valore di riserva preso dai token del sito, e la azzera
 * sulla propria radice per non ereditare i valori di un blocco esterno. PropertyMap unisce queste costanti alle sue.
 *
 * Gruppi: controls (nc/collection, nc/search, nc/slider: pillole, tendine, campi, frecce), buttons (nc/collection,
 * nc/search: Applica, Chiudi, Mostra altri, Cerca), choices (nc/collection: stato attivo e pannello a comparsa),
 * carousel (nc/slider: misura delle frecce, punti, distanza dal bordo), link (nc/post-title),
 * results (nc/collection: colonne e spazio della griglia dei risultati, anche per breakpoint),
 * fields (nc/native-form: etichette, testi di aiuto, legend dei gruppi, segni obbligatorio e facoltativo, altezza dei campi).
 */
final class PartMap
{
    public const MAP = [
        'controlBackground' => ['controls', '--nc-control-bg'],
        'controlColor' => ['controls', '--nc-control-color'],
        'controlBorder' => ['controls', '--nc-control-border'],
        'controlRadius' => ['controls', '--nc-control-radius'],
        'controlPadding' => ['controls', '--nc-control-padding'],
        'controlFocus' => ['controls', '--nc-control-focus'],
        'buttonBackground' => ['buttons', '--nc-control-button-bg'],
        'buttonColor' => ['buttons', '--nc-control-button-color'],
        'buttonBorder' => ['buttons', '--nc-control-button-border'],
        'buttonRadius' => ['buttons', '--nc-control-button-radius'],
        'buttonPadding' => ['buttons', '--nc-control-button-padding'],
        'controlBackgroundActive' => ['choices', '--nc-control-active-bg'],
        'controlColorActive' => ['choices', '--nc-control-active-color'],
        'controlBorderActive' => ['choices', '--nc-control-active-border'],
        'popoverBackground' => ['choices', '--nc-control-popover-bg'],
        'controlSize' => ['carousel', '--nc-control-size'],
        'dotColor' => ['carousel', '--nc-control-dot'],
        'dotColorActive' => ['carousel', '--nc-control-dot-active'],
        'controlsInset' => ['carousel', '--nc-control-inset'],
        'linkColor' => ['link', '--nc-link-color'],
        'linkColorHover' => ['link', '--nc-link-color-hover'],
        'linkDecoration' => ['link', '--nc-link-decoration'],
        'linkDecorationHover' => ['link', '--nc-link-decoration-hover'],
        'resultsColumns' => ['results', '--nc-collection-columns'],
        'resultsGap' => ['results', '--nc-collection-gap'],
        'labelColor' => ['fields', '--nc-form-label-color'],
        'labelSize' => ['fields', '--nc-form-label-size'],
        'labelWeight' => ['fields', '--nc-form-label-weight'],
        'helpColor' => ['fields', '--nc-form-help-color'],
        'legendColor' => ['fields', '--nc-form-legend-color'],
        'legendSize' => ['fields', '--nc-form-legend-size'],
        'legendWeight' => ['fields', '--nc-form-legend-weight'],
        'legendLine' => ['fields', '--nc-form-legend-line'],
        'markColor' => ['fields', '--nc-form-mark-color'],
        'optionalColor' => ['fields', '--nc-form-optional-color'],
        'fieldGap' => ['fields', '--nc-form-field-gap'],
        'fieldMinHeight' => ['fields', '--nc-form-field-min-height'],
        'controlBorderHover' => ['fields', '--nc-form-control-border-hover'], // bordo completo, come controlBorder
        'optionalWeight' => ['fields', '--nc-form-optional-weight'],
        'optionalSize' => ['fields', '--nc-form-optional-size'],
        'numberSize' => ['fields', '--nc-form-number-size'],
        'numberBorder' => ['fields', '--nc-form-number-border'],
        'numberColor' => ['fields', '--nc-form-number-color'],
        'numberFont' => ['fields', '--nc-form-number-font'],
        'numberFontSize' => ['fields', '--nc-form-number-font-size'],
        'numberWeight' => ['fields', '--nc-form-number-weight'],
        'controlMinHeight' => ['fields', '--nc-form-control-min-height'],
        'placeholderColor' => ['fields', '--nc-form-placeholder-color'],
        'checkSize' => ['fields', '--nc-form-check-size'],
        'checkAccent' => ['fields', '--nc-form-check-accent'],
        'navJustify' => ['fields', '--nc-form-nav-justify'],
    ];

    /** Chiavi con un elenco chiuso di valori. */
    public const ENUMS = [
        'linkDecoration' => ['none', 'underline', 'inherit'],
        'linkDecorationHover' => ['none', 'underline', 'inherit'],
        'navJustify' => ['flex-start', 'center', 'flex-end', 'space-between'],
    ];

    /** Gruppo di token per chiave (TokenMatcher): un valore uguale a un token va scritto come token. */
    public const TOKENS = [
        'color' => [
            'controlBackground', 'controlColor', 'controlFocus', 'buttonBackground', 'buttonColor',
            'controlBackgroundActive', 'controlColorActive', 'popoverBackground', 'dotColor', 'dotColorActive',
            'linkColor', 'linkColorHover', 'labelColor', 'helpColor', 'legendColor', 'legendLine', 'markColor', 'optionalColor',
            'numberColor', 'placeholderColor', 'checkAccent',
        ],
        'space' => ['controlPadding', 'buttonPadding', 'controlsInset', 'resultsGap', 'fieldGap'],
        'radius' => ['controlRadius', 'buttonRadius'],
        'font' => ['numberFont'],
    ];
}
