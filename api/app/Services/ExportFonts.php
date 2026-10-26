<?php

namespace App\Services;

use DOMDocument;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class ExportFonts
{
    public const CJK_FAMILY = 'Noto Sans SC';

    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public static function needsCjk(array $document): bool
    {
        return preg_match('/\p{sc=Han}/u', json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === 1;
    }

    public static function path(string $weight): string
    {
        return resource_path('fonts/NotoSansSC-'.$weight);
    }

    /** Embed complete, licensed TrueType faces so the document is editable on other devices. */
    public static function embedDocx(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot open document for font embedding.');
        }
        $temporaryFonts = [];
        try {
            $fonts = self::xml($zip, 'word/fontTable.xml');
            $family = $fonts->createElementNS(self::WORD_NS, 'w:font');
            $family->setAttributeNS(self::WORD_NS, 'w:name', self::CJK_FAMILY);
            $fonts->documentElement->appendChild($family);
            $relationships = new DOMDocument('1.0', 'UTF-8');
            $root = $relationships->createElementNS('http://schemas.openxmlformats.org/package/2006/relationships', 'Relationships');
            $relationships->appendChild($root);
            foreach (['Regular', 'Bold'] as $weight) {
                $key = strtoupper((string) Str::uuid());
                $temporary = tempnam(sys_get_temp_dir(), 'projectbrief-font-');
                if ($temporary === false) {
                    throw new RuntimeException('Cannot create temporary font.');
                }
                $temporaryFonts[] = $temporary;
                if (! copy(self::path($weight).'.ttf', $temporary)) {
                    throw new RuntimeException('CJK font is unavailable.');
                }
                $stream = fopen($temporary, 'r+b');
                if ($stream === false) {
                    throw new RuntimeException('Cannot open temporary font.');
                }
                $bytes = fread($stream, 32);
                // ECMA-376 font embedding: reverse GUID bytes, XOR the first 32 bytes twice.
                $mask = strrev(hex2bin(str_replace('-', '', $key)));
                for ($index = 0; $index < 32; $index++) {
                    $bytes[$index] = $bytes[$index] ^ $mask[$index % 16];
                }
                rewind($stream);
                fwrite($stream, $bytes);
                fclose($stream);
                $target = 'fonts/NotoSansSC-'.$weight.'.odttf';
                $zip->addFile($temporary, 'word/'.$target);
                $relationship = $relationships->createElementNS($root->namespaceURI, 'Relationship');
                $relationship->setAttribute('Id', 'cjk'.$weight);
                $relationship->setAttribute('Type', self::REL_NS.'/font');
                $relationship->setAttribute('Target', $target);
                $root->appendChild($relationship);
                $embedding = $fonts->createElementNS(self::WORD_NS, 'w:embed'.$weight);
                $embedding->setAttributeNS(self::REL_NS, 'r:id', 'cjk'.$weight);
                $embedding->setAttributeNS(self::WORD_NS, 'w:fontKey', '{'.$key.'}');
                $family->appendChild($embedding);
            }
            $zip->addFromString('word/fontTable.xml', $fonts->saveXML());
            $zip->addFromString('word/_rels/fontTable.xml.rels', $relationships->saveXML());
            $settings = self::xml($zip, 'word/settings.xml');
            $settings->documentElement->appendChild($settings->createElementNS(self::WORD_NS, 'w:embedTrueTypeFonts'));
            $zip->addFromString('word/settings.xml', $settings->saveXML());
            $types = self::xml($zip, '[Content_Types].xml');
            foreach (['odttf' => 'application/vnd.openxmlformats-officedocument.obfuscatedFont', 'txt' => 'text/plain'] as $extension => $type) {
                $entry = $types->createElementNS($types->documentElement->namespaceURI, 'Default');
                $entry->setAttribute('Extension', $extension);
                $entry->setAttribute('ContentType', $type);
                $types->documentElement->appendChild($entry);
            }
            $zip->addFromString('[Content_Types].xml', $types->saveXML());
            $zip->addFromString('word/fonts/NotoSansSC-OFL.txt', file_get_contents(resource_path('fonts/NotoSansSC-OFL.txt')));
        } finally {
            $zip->close();
            foreach ($temporaryFonts as $temporary) {
                unlink($temporary);
            }
        }
    }

    private static function xml(ZipArchive $zip, string $name): DOMDocument
    {
        $xml = new DOMDocument;
        if (! $xml->loadXML($zip->getFromName($name), LIBXML_NONET)) {
            throw new RuntimeException('Invalid document font metadata.');
        }

        return $xml;
    }
}
