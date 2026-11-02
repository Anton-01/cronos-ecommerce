<?php

declare(strict_types=1);

namespace Database\Seeders;

use Cronos\Content\Application\PageService;
use Cronos\Content\Domain\Ports\PageRepository;
use Illuminate\Database\Seeder;

/**
 * The pages every shop needs, as DRAFTS with neutral starter text. They are not published: the text of a privacy notice or of the terms is a
 * legal matter of each business and must be written or reviewed by whoever answers for it, then published from the back office.
 */
final class ContentSeeder extends Seeder
{
    public function run(PageService $pages, PageRepository $repo): void
    {
        $note = ['es-MX' => "> **Texto de ejemplo.** Reemplázalo con el contenido real de tu negocio antes de publicar.\n\n", 'en-US' => "> **Sample text.** Replace it with your business's real content before publishing.\n\n"];
        $starter = [
            ['about', 'Nosotros', 'About us', 'nosotros', 'about-us', 'Somos una tienda en línea de listones y moños. Cuéntanos aquí quién eres y qué te hace distinto.', 'We are an online ribbon and bow shop. Tell your story here.'],
            ['shipping', 'Envíos', 'Shipping', 'envios', 'shipping', "## Cómo enviamos\n\nExplica tiempos, costos, paqueterías y cobertura de cada tienda.", "## How we ship\n\nExplain delivery times, costs, carriers and coverage for each store."],
            ['returns', 'Cambios y devoluciones', 'Returns', 'cambios-y-devoluciones', 'returns', "## Política de devoluciones\n\nExplica plazos y condiciones. Los listones cortados a medida suelen no admitir devolución: indícalo aquí.", "## Return policy\n\nExplain deadlines and conditions. Ribbon cut to length usually cannot be returned: say so here."],
            ['faq', 'Preguntas frecuentes', 'FAQ', 'preguntas-frecuentes', 'faq', "### ¿Cómo se vende el listón?\n\nPor metro (en múltiplos de 0.5 m) o por rollo completo.", "### How is ribbon sold?\n\nBy the metre (in multiples of 0.5 m) or by the whole roll."],
            ['privacy', 'Aviso de privacidad', 'Privacy policy', 'aviso-de-privacidad', 'privacy-policy', 'Aquí va el aviso de privacidad de tu negocio.', "Your business's privacy policy goes here."],
            ['terms', 'Términos y condiciones', 'Terms and conditions', 'terminos-y-condiciones', 'terms-and-conditions', 'Aquí van los términos y condiciones de tu negocio.', "Your business's terms and conditions go here."],
        ];
        foreach ($starter as $i => [$key, $titleEs, $titleEn, $slugEs, $slugEn, $bodyEs, $bodyEn]) {
            if ($repo->keyExists($key, null)) {
                continue;
            }
            $pages->create($key, [], $i + 1, [
                'es-MX' => ['title' => $titleEs, 'slug' => $slugEs, 'body_md' => $note['es-MX'].$bodyEs],
                'en-US' => ['title' => $titleEn, 'slug' => $slugEn, 'body_md' => $note['en-US'].$bodyEn],
            ], null);
        }
    }
}
