<?php

declare(strict_types=1);

namespace Nucleo\Blocks;

/** Contesto che conosce le colonne in cui sta il nodo in compilazione: serve a nc/image per sizes. */
interface ColumnAware
{
    /**
     * Valore di sizes per un nodo dentro colonne (style.columns di un antenato), con la larghezza massima
     * del file se nota. Null fuori da colonne: vale la regola di WordPress.
     */
    public function columnSizes(?int $maxWidth): ?string;
}
