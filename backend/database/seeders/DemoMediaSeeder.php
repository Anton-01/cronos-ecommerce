<?php

declare(strict_types=1);

namespace Database\Seeders;

use Cronos\Appearance\Application\ThemeService;
use Cronos\Catalog\Application\CategoryManager;
use Cronos\Catalog\Domain\Ports\ProductGallery;
use Cronos\Media\Application\Commands\ProcessMedia;
use Cronos\Media\Application\Commands\UploadMedia;
use Cronos\Media\Application\Handlers\ProcessMediaHandler;
use Cronos\Media\Application\Handlers\UploadMediaHandler;
use Database\Seeders\Support\PlaceholderArt;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Development-only: neutral drawn artwork (see PlaceholderArt) pushed through the REAL media pipeline (slot checks, WebP/AVIF renditions,
 * srcset) and wired into the catalogue and the home page, so the storefront can be judged with pictures. Run after DemoCatalogSeeder.
 * The real photography replaces these from the back office; nothing here is a photo.
 */
final class DemoMediaSeeder extends Seeder
{
    public function run(UploadMediaHandler $upload, ProcessMediaHandler $process, ProductGallery $gallery, CategoryManager $categories, ThemeService $theme): void
    {
        $put = function (string $path, string $name, string $slot) use ($upload, $process): string {
            $media = $upload(new UploadMedia($path, $name, 'library', null, null, null, $slot));
            $process(new ProcessMedia($media->id));
            @unlink($path);

            return $media->id;
        };

        // Products: a spool for ribbons, a bow for bows (three colourways each).
        $variant = 0;
        foreach (DB::table('product_translations')->join('products', 'products.id', '=', 'product_translations.product_id')
            ->where('product_translations.locale', 'es-MX')->whereNull('products.parent_id')->orderBy('products.sku')->get(['products.id', 'products.sku', 'product_translations.name']) as $product) {
            $isBow = str_starts_with((string) $product->sku, 'MONO');
            $path = $isBow ? PlaceholderArt::bow($variant % 3) : PlaceholderArt::product($variant % 3);
            $gallery->attach($product->id, $put($path, (string) $product->name, 'product'));
            $variant++;
        }

        // Categories: round tiles.
        foreach (['listones' => 0, 'monos' => 2, 'accesorios' => 1] as $slug => $v) {
            $id = DB::table('category_translations')->where('slug', $slug)->value('category_id');
            $id !== null && $categories->update($id, ['image_media_id' => $put(PlaceholderArt::category($v), $slug, 'category')]);
        }

        // Home: a three-slide hero (desktop + phone art), the categories, the newest products and a banner.
        $slide = fn (int $v, string $kickerEs, string $kickerEn, string $titleEs, string $titleEn, string $subEs, string $subEn, string $ctaEs, string $ctaEn, string $url) => [
            'image' => $put(PlaceholderArt::hero(2560, 1067, $v), "hero-{$v}", 'hero'),
            'imageMobile' => $put(PlaceholderArt::hero(1200, 1500, $v), "hero-mobile-{$v}", 'hero_mobile'),
            'kicker' => ['es-MX' => $kickerEs, 'en-US' => $kickerEn], 'title' => ['es-MX' => $titleEs, 'en-US' => $titleEn],
            'subtitle' => ['es-MX' => $subEs, 'en-US' => $subEn], 'priceText' => ['es-MX' => '', 'en-US' => ''],
            'ctaLabel' => ['es-MX' => $ctaEs, 'en-US' => $ctaEn], 'ctaUrl' => $url, 'align' => 'left', 'overlay' => 0,
        ];
        $home = [
            ['id' => 'hero', 'type' => 'hero', 'enabled' => true, 'settings' => ['autoplay' => 6, 'slides' => [
                $slide(0, 'Nueva colección', 'New collection', 'Listones que dan vida a tus regalos', 'Ribbons that bring gifts to life', 'Por metro o por rollo completo, en satén y organza.', 'By the metre or the whole roll, in satin and organza.', 'Ver listones', 'Shop ribbons', '/categorias/listones'),
                $slide(1, 'Hecho a tu medida', 'Made to measure', 'Moños para cada ocasión', 'Bows for every occasion', 'Clásicos, navideños y de regalo.', 'Classic, festive and gift bows.', 'Ver moños', 'Shop bows', '/categorias/monos'),
                $slide(2, 'Pedidos especiales', 'Special orders', '¿Buscas algo distinto?', 'Looking for something different?', 'Cuéntanos tu idea y la cotizamos.', 'Tell us your idea and we will quote it.', 'Pedido personalizado', 'Custom order', '/custom-orders'),
            ]]],
            ['id' => 'categories', 'type' => 'categories', 'enabled' => true, 'settings' => ['title' => ['es-MX' => 'Nuestras categorías', 'en-US' => 'Our categories'], 'subtitle' => ['es-MX' => '', 'en-US' => ''], 'mode' => 'auto', 'categoryIds' => [], 'max' => 10]],
            ['id' => 'new-products', 'type' => 'products', 'enabled' => true, 'settings' => ['title' => ['es-MX' => 'Novedades', 'en-US' => 'New arrivals'], 'source' => 'newest', 'categoryId' => null, 'productIds' => [], 'limit' => 10]],
        ];
        $theme->saveDraft(['home' => $home], null);
        $theme->publish('Demo home page', null);

        $this->call(DemoVariationPhotosSeeder::class);
    }
}
