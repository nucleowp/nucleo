<?php

declare(strict_types=1);

namespace Nucleo\Runtime;

use Nucleo\Blocks\DynamicRenderer;

/**
 * Request-time slot dispatch: slot block name → DynamicRenderer class.
 * Loads only the classes of the slots on the page; never loads the block registry.
 */
final class Slots
{
    /** Id del documento (pagina o layout) di cui il runtime sta riempiendo gli slot; 0 se sconosciuto. */
    private static int $document = 0;

    public static function setDocument(int $postId): void
    {
        self::$document = $postId;
    }

    public static function document(): int
    {
        return self::$document;
    }

    /** @return array<string,string> */
    private static function handlers(): array
    {
        return [
        'nc/post-title' => 'Nucleo\\Blocks\\Core\\PostTitleRenderer',
        'nc/post-content' => 'Nucleo\\Blocks\\Core\\PostContentRenderer',
        'nc/post-image' => 'Nucleo\\Blocks\\Core\\PostImageRenderer',
        'nc/post-meta' => 'Nucleo\\Blocks\\Core\\PostMetaRenderer',
        'nc/breadcrumbs' => 'Nucleo\\Blocks\\Core\\BreadcrumbsRenderer',
        'nc/search' => 'Nucleo\\Blocks\\Core\\SearchRenderer',
        'nc/pagination' => 'Nucleo\\Blocks\\Core\\PaginationRenderer',
        'nc/archive-title' => 'Nucleo\\Blocks\\Core\\ArchiveTitleRenderer',
        'nc/archive-description' => 'Nucleo\\Blocks\\Core\\ArchiveDescriptionRenderer',
        'nc/visibility' => 'Nucleo\\Runtime\\VisibilitySlot',
        'nc/bind' => 'Nucleo\\Bindings\\BindSlot',
        'nc/form' => 'Nucleo\\Integrations\\Forms\\FormRenderer',
        'nc/collection' => 'Nucleo\\Runtime\\Collection\\CollectionSlot',
        'nc/native-form' => 'Nucleo\\Forms\\NativeFormSlot',
        'nc/repeater' => 'Nucleo\\Blocks\\Core\\RepeaterRenderer',
        'nc/query' => 'Nucleo\\Blocks\\Core\\QueryRenderer',
        // WooCommerce (classes exist only in that integration).
        'woo/price' => 'Nucleo\\Integrations\\Woo\\PriceRenderer',
        'woo/stock' => 'Nucleo\\Integrations\\Woo\\StockRenderer',
        'woo/add-to-cart' => 'Nucleo\\Integrations\\Woo\\AddToCartRenderer',
        'woo/gallery' => 'Nucleo\\Integrations\\Woo\\GalleryRenderer',
        'woo/short-description' => 'Nucleo\\Integrations\\Woo\\ShortDescriptionRenderer',
        'woo/attributes' => 'Nucleo\\Integrations\\Woo\\AttributesRenderer',
        'woo/related' => 'Nucleo\\Integrations\\Woo\\RelatedRenderer',
        'woo/mini-cart' => 'Nucleo\\Integrations\\Woo\\MiniCartRenderer',
        'woo/notices' => 'Nucleo\\Integrations\\Woo\\NoticesRenderer',
        'woo/tabs' => 'Nucleo\\Integrations\\Woo\\ProductTabsRenderer',
        'woo/upsell' => 'Nucleo\\Integrations\\Woo\\UpsellRenderer',
        'woo/cart' => 'Nucleo\\Integrations\\Woo\\CartRenderer',
        'woo/checkout' => 'Nucleo\\Integrations\\Woo\\CheckoutRenderer',
        'woo/account' => 'Nucleo\\Integrations\\Woo\\AccountRenderer',
        // Phase 1 names, kept for documents compiled before Phase 2.
        'post.title' => 'Nucleo\\Blocks\\Core\\PostTitleRenderer',
        'post.date' => 'Nucleo\\Blocks\\Core\\PostMetaRenderer',
        ];
    }

    public static function known(string $block): bool
    {
        return isset(self::handlers()[$block]);
    }

    /** @param array<string,mixed> $data */
    public static function render(string $block, array $data): string
    {
        $class = self::handlers()[$block] ?? null;
        if ($class === null || !class_exists($class)) {
            return '';
        }
        $renderer = new $class();

        return $renderer instanceof DynamicRenderer ? $renderer->renderSlot($data) : '';
    }
}
