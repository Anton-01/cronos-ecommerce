<?php

declare(strict_types=1);

namespace Cronos\Appearance\Infrastructure\Branding;

use Cronos\Appearance\Domain\Exceptions\ThemeError;
use Cronos\Appearance\Domain\Ports\SvgSanitizer;

/**
 * An SVG is a small program as much as a picture: it can carry scripts, event handlers, external images and styles that load remote
 * files. A logo only needs shapes, paths, gradients and text, so everything outside that list is removed (allow-list, not block-list).
 */
final class DomSvgSanitizer implements SvgSanitizer
{
    private const ELEMENTS = ['svg', 'g', 'defs', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'title', 'desc', 'use', 'symbol'];

    private const ATTRIBUTES = [
        'id', 'class', 'd', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'width', 'height', 'viewbox', 'preserveaspectratio', 'points', 'transform', 'fill', 'fill-opacity', 'fill-rule', 'clip-rule',
        'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'offset', 'stop-color', 'stop-opacity', 'gradientunits',
        'gradienttransform', 'fx', 'fy', 'spreadmethod', 'clip-path', 'mask', 'xmlns', 'version', 'font-family', 'font-size', 'font-weight', 'text-anchor', 'dx', 'dy', 'letter-spacing', 'href', 'xlink:href', 'xmlns:xlink', 'style',
    ];

    public function sanitize(string $svg): string
    {
        // A DOCTYPE with entities is how an SVG pulls in local files or explodes in size ("billion laughs"): no DTD at all.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $svg) === 1) {
            throw new ThemeError('El SVG no puede declarar entidades ni DOCTYPE.', 'SVG_UNSAFE');
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        ($loaded && $doc->documentElement !== null && strtolower($doc->documentElement->localName) === 'svg') || throw new ThemeError('El archivo no es un SVG válido.', 'SVG_INVALID');

        $this->clean($doc->documentElement);
        $root = $doc->documentElement;
        $root->hasAttribute('viewBox') || ($root->hasAttribute('width') && $root->hasAttribute('height')) || throw new ThemeError('El SVG necesita viewBox (o ancho y alto) para poder escalarse.', 'SVG_INVALID');
        $root->setAttribute('xmlns', 'http://www.w3.org/2000/svg');

        return (string) $doc->saveXML($root);
    }

    private function clean(\DOMElement $el): void
    {
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                if (! in_array(strtolower($child->localName), self::ELEMENTS, true)) {
                    $el->removeChild($child); // script, foreignObject, image, a, animate, set, style, iframe...

                    continue;
                }
                $this->clean($child);
            } elseif ($child instanceof \DOMText || $child instanceof \DOMCdataSection) {
                continue;
            } else {
                $el->removeChild($child); // comments, processing instructions
            }
        }

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = trim($attr->nodeValue ?? '');
            $drop = ! in_array($name, self::ATTRIBUTES, true) || str_starts_with($name, 'on');
            if (! $drop && in_array($name, ['href', 'xlink:href'], true)) {
                $drop = ! str_starts_with($value, '#'); // only references inside the same file
            }
            if (! $drop && preg_match('/javascript:|data:|expression\s*\(|@import|behavior:|-moz-binding/i', $value) === 1) {
                $drop = true;
            }
            if (! $drop && preg_match('/url\s*\(\s*[\'"]?\s*(?!#)/i', $value) === 1) {
                $drop = true; // url(http://…) in fill, style, clip-path…: only url(#id) is allowed
            }
            $drop && $el->removeAttributeNode($attr);
        }
    }
}
