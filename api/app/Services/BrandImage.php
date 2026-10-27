<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMText;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class BrandImage
{
    public function invalid(): never
    {
        throw ValidationException::withMessages(['file' => trans('branding.invalid_image')]);
    }

    /** Strict static SVG allowlist: no CSS, scripts, event attributes, links, entities or external resources. */
    public function svg(string $source): string
    {
        if (strlen($source) > 2 * 1024 * 1024 || preg_match('/<!DOCTYPE|<!ENTITY/i', $source)) {
            $this->invalid();
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($source, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if (! $loaded || ! $root || $root->localName !== 'svg' || $root->namespaceURI !== 'http://www.w3.org/2000/svg') {
            $this->invalid();
        }
        $tags = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'defs', 'linearGradient', 'radialGradient', 'stop', 'clipPath', 'title', 'desc', 'text', 'tspan'];
        $attributes = ['viewBox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'opacity', 'transform', 'id', 'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform', 'fx', 'fy', 'clip-path', 'font-size', 'font-family', 'font-weight', 'text-anchor', 'dominant-baseline'];
        $count = 0;
        $clean = function (DOMElement $element) use (&$clean, &$count, $tags, $attributes): void {
            if (++$count > 3000) {
                $this->invalid();
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                $value = trim($attribute->value);
                if (! in_array($attribute->name, $attributes, true) || strlen($value) > 100000 || preg_match('/[<>]|javascript|data:|https?:|\/\/|[\\\\]/i', $value) || (str_contains(strtolower($value), 'url') && ! preg_match('/^url\(#[a-zA-Z0-9_-]+\)$/D', $value))) {
                    $element->removeAttributeNode($attribute);
                }
            }
            foreach (iterator_to_array($element->childNodes) as $child) {
                if ($child instanceof DOMElement) {
                    if (! in_array($child->localName, $tags, true) || $child->namespaceURI !== 'http://www.w3.org/2000/svg') {
                        $element->removeChild($child);
                    } else {
                        $clean($child);
                    }
                } elseif (! ($child instanceof DOMText) || ! in_array($element->localName, ['title', 'desc', 'text', 'tspan'], true)) {
                    $element->removeChild($child);
                }
            }
        };
        $clean($root);
        $root->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        $viewBox = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
        if (count($viewBox) === 4 && is_numeric($viewBox[2]) && is_numeric($viewBox[3]) && (float) $viewBox[2] > 0 && (float) $viewBox[3] > 0) {
            $ratio = min(1, 1024 / max((float) $viewBox[2], (float) $viewBox[3]));
            $root->setAttribute('width', (string) max(1, (int) ((float) $viewBox[2] * $ratio)));
            $root->setAttribute('height', (string) max(1, (int) ((float) $viewBox[3] * $ratio)));
        } else {
            $width = (float) $root->getAttribute('width');
            $height = (float) $root->getAttribute('height');
            if ($width <= 0 || $height <= 0 || $width > 4096 || $height > 4096) {
                $this->invalid();
            }
            $root->setAttribute('viewBox', '0 0 '.$width.' '.$height);
            $ratio = min(1, 1024 / max($width, $height));
            $root->setAttribute('width', (string) max(1, (int) ($width * $ratio)));
            $root->setAttribute('height', (string) max(1, (int) ($height * $ratio)));
        }

        return $document->saveXML($root);
    }

    /** Decode and re-encode raster images, stripping metadata and limiting memory/dimensions. */
    public function png(UploadedFile $file, string $kind): string
    {
        $info = @getimagesize($file->getPathname());
        if (! $info || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true) || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 16000000) {
            $this->invalid();
        }
        $image = @imagecreatefromstring(file_get_contents($file->getPathname()));
        if (! $image) {
            $this->invalid();
        }
        $max = $kind === 'favicon' ? 256 : 1024;
        $scale = min(1, $max / max($info[0], $info[1]));
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));
        $canvas = imagecreatetruecolor($kind === 'favicon' ? 256 : $width, $kind === 'favicon' ? 256 : $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $image, $kind === 'favicon' ? (int) ((256 - $width) / 2) : 0, $kind === 'favicon' ? (int) ((256 - $height) / 2) : 0, 0, 0, $width, $height, $info[0], $info[1]);
        ob_start();
        imagepng($canvas, null, 8);
        $bytes = ob_get_clean();
        imagedestroy($image);
        imagedestroy($canvas);
        if (! $bytes) {
            $this->invalid();
        }

        return $bytes;
    }
}
