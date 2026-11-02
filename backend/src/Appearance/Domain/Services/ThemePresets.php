<?php

declare(strict_types=1);

namespace Cronos\Appearance\Domain\Services;

/** Starting points: pick one, then adjust anything. They only change colours, type and shapes. */
final class ThemePresets
{
    /** @return array<string, array{label: string, description: string, tokens: array<string, mixed>}> */
    public static function all(): array
    {
        return [
            'calido' => ['label' => 'Cálido (referencia)', 'description' => 'Naranja suave sobre fondo crema, pie negro. El look del sitio de referencia.', 'tokens' => []],
            'neutro' => ['label' => 'Neutro minimalista', 'description' => 'Grises y negro, esquinas rectas, tipografía limpia.', 'tokens' => [
                'colors' => ['primary' => '#1F1F1F', 'secondary' => '#1F1F1F', 'sale' => '#B3261E', 'background' => '#F7F7F7', 'headerBg' => '#FFFFFF', 'topbarBg' => '#1F1F1F', 'topbarText' => '#E6E6E6', 'ribbonBg' => '#EDEDED', 'footerBg' => '#161616', 'border' => '#E0E0E0'],
                'typography' => ['headingFont' => 'Inter', 'bodyFont' => 'Inter', 'headingWeight' => 700, 'bodyWeight' => 400],
                'shape' => ['radiusCard' => 4, 'radiusButton' => 4, 'radiusInput' => 4, 'radiusBadge' => 2, 'categoryShape' => 'square', 'cardStyle' => 'bordered'],
                'layout' => ['backgroundTexture' => 'none']]],
            'bosque' => ['label' => 'Bosque', 'description' => 'Verdes profundos y tipografía con serifa para títulos.', 'tokens' => [
                'colors' => ['primary' => '#2F6B4F', 'secondary' => '#1E3B2E', 'sale' => '#C2573B', 'background' => '#F4F1EA', 'headerBg' => '#FBF9F4', 'topbarBg' => '#1E3B2E', 'topbarText' => '#DDE8E1', 'ribbonBg' => '#DCE8DF', 'footerBg' => '#16281F'],
                'typography' => ['headingFont' => 'Playfair Display', 'bodyFont' => 'Lora', 'headingWeight' => 700, 'bodyWeight' => 400],
                'shape' => ['radiusCard' => 8, 'radiusButton' => 6, 'radiusInput' => 6, 'categoryShape' => 'rounded']]],
            'oceano' => ['label' => 'Océano', 'description' => 'Azules frescos, esquinas redondeadas y sombras suaves.', 'tokens' => [
                'colors' => ['primary' => '#1F6FB2', 'secondary' => '#12344D', 'sale' => '#E4572E', 'background' => '#EEF3F7', 'headerBg' => '#FFFFFF', 'topbarBg' => '#12344D', 'topbarText' => '#D6E4EF', 'ribbonBg' => '#D9E9F5', 'footerBg' => '#0B2233'],
                'typography' => ['headingFont' => 'Poppins', 'bodyFont' => 'DM Sans', 'headingWeight' => 600, 'bodyWeight' => 400],
                'shape' => ['radiusCard' => 14, 'radiusButton' => 12, 'radiusInput' => 12, 'cardStyle' => 'shadow'],
                'layout' => ['backgroundTexture' => 'none']]],
            'oscuro' => ['label' => 'Oscuro elegante', 'description' => 'Fondo oscuro con acento dorado.', 'tokens' => [
                'colors' => ['primary' => '#D4A857', 'secondary' => '#F2F2F2', 'onSecondary' => '#111111', 'sale' => '#E07A5F', 'background' => '#121212', 'surface' => '#1C1C1C', 'text' => '#B8B8B8', 'heading' => '#F5F5F5', 'link' => '#EDEDED',
                    'border' => '#2E2E2E', 'headerBg' => '#181818', 'headerText' => '#EDEDED', 'topbarBg' => '#0C0C0C', 'topbarText' => '#B0B0B0', 'ribbonBg' => '#2A2418', 'ribbonText' => '#E9D8A6', 'footerBg' => '#0A0A0A', 'footerText' => '#A8A8A8'],
                'typography' => ['headingFont' => 'Cormorant Garamond', 'bodyFont' => 'Manrope', 'headingWeight' => 600, 'bodyWeight' => 400],
                'shape' => ['radiusCard' => 6, 'radiusButton' => 2, 'radiusInput' => 2, 'cardStyle' => 'bordered'],
                'layout' => ['backgroundTexture' => 'none']]],
        ];
    }
}
