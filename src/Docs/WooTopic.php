<?php

declare(strict_types=1);

namespace Nucleo\Docs;

/** Topic "woocommerce" di nucleo/catalog-get: aree, ricette per pagina e strumenti dei blocchi woo/*. Testi in inglese. */
final class WooTopic
{
    /** @return array{topic:string,summary:string,rules:list<string>,data:array<string,mixed>} */
    public static function topic(): array
    {
        return [
            'topic' => 'woocommerce',
            'summary' => 'WooCommerce: the woo/* blocks, the product and product-archive layout areas, one recipe per page, and the checkout tools.',
            'rules' => [
                'The woo/* blocks and the product and product-archive areas exist only with WooCommerce active.',
                'Area product is the single product page. Area product-archive is the Shop and the product categories and tags.',
                'On a product or product-archive page these areas win over single and archive. Search wins over archive.',
                'Put the woo/* blocks of the product page in a product layout, not in a single layout, because single also applies to posts.',
                'Recipe product page: woo/gallery beside or above the product information.',
                'Recipe product page: woo/price, woo/stock and woo/short-description in the product information.',
                'Recipe product page: woo/add-to-cart below price and availability.',
                'Recipe product page: woo/attributes, woo/tabs, woo/related and woo/upsell below the product.',
                'Recipe product archive: woo/product-grid, with settings.category to choose the products shown.',
                'woo/product-grid shows a fixed list of products. It does not list the products of the current archive.',
                'Recipe cart page: woo/notices first, then woo/cart.',
                'woo/cart mode blocks uses the WooCommerce cart block and ignores children.',
                'woo/cart mode classic uses the cart shortcode.',
                'woo/cart mode nucleo places its children in the given order, with no wrappers or columns.',
                'woo/cart mode nucleo children: woo/cart-items, woo/cart-totals, woo/cart-coupon, woo/cart-shipping, woo/cart-actions, woo/cart-empty.',
                'The cart state is read on every request.',
                'Recipe checkout page: woo/notices first, then woo/checkout as the only other block.',
                'woo/checkout mode blocks uses the WooCommerce checkout block. Mode classic uses the checkout shortcode.',
                'Recipe mini cart: woo/mini-cart in the header layout, one per page.',
                'The mini cart display drawer opens a full-height side panel. Its freeShippingFrom is in the shop currency, and 0 hides the progress line.',
                'nucleo/woo-checkout-fields-set sets the custom checkout fields. It acts only with woo/checkout in mode blocks.',
                'nucleo/woo-checkout-parts-set sets the order note, terms, coupon, company, phone and address2 of the checkout.',
                'nucleo/woo-checkout-parts-set acts only with woo/checkout in mode blocks; company, phone and address2 also act in mode classic.',
                'Gap: no block lists the products of the current archive.',
                'Gap: woo/product-grid and nc/query are fixed lists. nc/collection with postType product and filters is the catalog.',
                'Gap: nc/pagination follows the main query.',
                'Report a Woo gap with gap-report.',
            ],
            'data' => [
                'areas' => ['product', 'product-archive'],
                'pages' => [
                    'product' => ['woo/gallery', 'woo/price', 'woo/stock', 'woo/short-description', 'woo/attributes', 'woo/add-to-cart', 'woo/tabs', 'woo/related', 'woo/upsell'],
                    'product-archive' => ['woo/product-grid'],
                    'cart' => ['woo/notices', 'woo/cart'],
                    'checkout' => ['woo/notices', 'woo/checkout'],
                    'mini-cart' => ['woo/mini-cart'],
                ],
                'tools' => ['nucleo/woo-checkout-fields-set', 'nucleo/woo-checkout-parts-set'],
            ],
        ];
    }
}
