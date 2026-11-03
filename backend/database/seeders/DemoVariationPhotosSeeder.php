<?php

declare(strict_types=1);

namespace Database\Seeders;

use Cronos\Catalog\Domain\Ports\ProductGallery;
use Cronos\Media\Application\Commands\ProcessMedia;
use Cronos\Media\Application\Commands\UploadMedia;
use Cronos\Media\Application\Handlers\ProcessMediaHandler;
use Cronos\Media\Application\Handlers\UploadMediaHandler;
use Database\Seeders\Support\PlaceholderArt;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Development-only: one neutral spool picture per colour on the variations of the plain ribbon, so the storefront can show the photo changing with the colour. */
final class DemoVariationPhotosSeeder extends Seeder
{
    /** colour value code → colourway of PlaceholderArt */
    private const WAY = ['rojo' => 2, 'azul' => 1, 'dorado' => 0];

    public function run(UploadMediaHandler $upload, ProcessMediaHandler $process, ProductGallery $gallery): void
    {
        $variations = DB::table('products as v')->join('product_attribute_values as pav', 'pav.product_id', '=', 'v.id')->join('attribute_values as av', 'av.id', '=', 'pav.attribute_value_id')
            ->where('v.type', 'variation')->whereNull('v.deleted_at')->whereIn('av.code', array_keys(self::WAY))->get(['v.id', 'v.sku', 'av.code']);

        foreach ($variations as $v) {
            if ($gallery->list($v->id) !== []) {
                continue;
            }
            $media = $upload(new UploadMedia(PlaceholderArt::product(self::WAY[$v->code]), "{$v->sku}.png", 'library', null, null, null, 'product'));
            $process(new ProcessMedia($media->id));
            $gallery->attach($v->id, $media->id);
        }
    }
}
