# Dati

`font-metrics.json`: metriche dei font per calcolare `size-adjust` e gli override del font di ripiego.
Formato: `"Famiglia": [categoria, ascent, descent, lineGap, unitsPerEm, xWidthAvg]`, con categoria `x` (sans-serif), `s` (serif) o `m` (monospace).
Origine: `@capsizecss/metrics` 3.7.0, licenza MIT (testo in `LICENSE-capsize-metrics`). Il file si legge solo all'import dei font, mai sul frontend.
