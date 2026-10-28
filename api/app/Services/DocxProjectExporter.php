<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Style\Language;
use PhpOffice\PhpWord\Style\Tab;
use RuntimeException;

class DocxProjectExporter
{
    private const WIDTH = 9978;

    public function render(array $document): string
    {
        $previousAsianFont = Settings::getDefaultAsianFontName();
        try {
            return $this->renderDocument($document);
        } finally {
            Settings::setDefaultAsianFontName($previousAsianFont);
        }
    }

    private function compact(string $value, int $maximum = 650): bool
    {
        return mb_strlen($value) <= $maximum && ! str_contains($value, "\t") && preg_match_all('/\R/u', $value) <= 8;
    }

    private function tableStyle(): array
    {
        return ['width' => self::WIDTH, 'unit' => 'dxa', 'layout' => 'fixed', 'cellMargin' => 0];
    }

    private function renderDocument(array $document): string
    {
        Settings::setOutputEscapingEnabled(true);
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(9.5);
        $cjk = ExportFonts::needsCjk($document);
        if ($cjk) {
            $word->setDefaultAsianFontName(ExportFonts::CJK_FAMILY);
            $word->getSettings()->setThemeFontLang(new Language('en-GB', 'zh-CN'));
        }
        $word->setDefaultParagraphStyle(['spaceAfter' => 0, 'lineHeight' => 1.25, 'widowControl' => true]);
        $word->addTitleStyle(0, ['size' => 17, 'bold' => true, 'color' => '000000'], ['spaceAfter' => 80, 'keepNext' => true]);
        $word->addTitleStyle(1, ['size' => 12.5, 'bold' => true, 'color' => '000000'], ['spaceBefore' => 240, 'spaceAfter' => 100, 'keepNext' => true, 'keepLines' => true]);
        $word->addTitleStyle(2, ['size' => 10.5, 'bold' => true, 'color' => '000000'], ['spaceBefore' => 160, 'spaceAfter' => 60, 'keepNext' => true, 'keepLines' => true]);
        $word->addParagraphStyle('SectionStart', ['basedOn' => 'Heading1', 'outlineLevel' => 0, 'pageBreakBefore' => true, 'keepNext' => true, 'keepLines' => true]);
        $section = $word->addSection(['pageSizeW' => 11906, 'pageSizeH' => 16838, 'marginTop' => 964, 'marginBottom' => 1100, 'marginLeft' => 964, 'marginRight' => 964, 'footerHeight' => 340]);
        $brand = $document['branding'] ?? [];
        $footer = $section->addFooter()->addTable($this->tableStyle());
        $footer->addRow();
        $footerStyle = ['size' => 7.5, 'color' => '656B75'];
        $footerLine = $footer->addCell(9000, ['noWrap' => false])->addTextRun(['spaceAfter' => 0]);
        $footerLine->addText(($brand['name'] ?? '') ?: $document['project']['name'], $footerStyle);
        if ($brand['platform'] ?? true) {
            $footerLine->addText(' · ', $footerStyle);
            $footerLine->addLink($brand['platformUrl'] ?? config('app.url'), $document['labels']['footer'], $footerStyle);
        }
        $footer->addCell(self::WIDTH - 9000, ['noWrap' => false])->addPreserveText('{PAGE} / {NUMPAGES}', $footerStyle, ['alignment' => 'right', 'spaceAfter' => 0]);
        if (! empty($brand['logo']) || ! empty($brand['name']) || ! empty($brand['address']) || ! empty($brand['contact'])) {
            $header = $section->addTable($this->tableStyle());
            $header->addRow();
            if (! empty($brand['logo'])) {
                $bytes = base64_decode(substr($brand['logo'], strpos($brand['logo'], ',') + 1), true);
                $size = getimagesizefromstring($bytes);
                $scale = min(86 / $size[0], 28 / $size[1]);
                $header->addCell(1960, ['valign' => 'top', 'noWrap' => false])->addImage($bytes, ['unit' => 'pt', 'width' => $size[0] * $scale, 'height' => $size[1] * $scale]);
            }
            $cell = $header->addCell(! empty($brand['logo']) ? self::WIDTH - 1960 : self::WIDTH, ['noWrap' => false]);
            if (! empty($brand['name'])) {
                $cell->addText($brand['name'], ['bold' => true, 'size' => 11.5, 'color' => '000000'], ['spaceAfter' => 40]);
            }
            foreach ([$brand['address'] ?? [], $brand['contact'] ?? []] as $lines) {
                if ($lines) {
                    $cell->addText(implode(' · ', $lines), ['size' => 8.5, 'color' => '525866'], ['spaceAfter' => 20]);
                }
            }
            $section->addText('', ['size' => 1], ['spaceBefore' => 100, 'spaceAfter' => 120, 'borderBottomSize' => 4, 'borderBottomColor' => 'CBD0D8']);
        }
        $section->addTitle($document['title'], 0);
        $section->addText($document['project']['name'], ['size' => 10, 'bold' => true], ['spaceAfter' => 60, 'keepNext' => true]);
        $meta = $section->addTextRun(['spaceAfter' => 120, 'keepNext' => true, 'tabs' => [new Tab('right', self::WIDTH)]]);
        if ($document['project']['website']) {
            if (preg_match('/^https?:\/\//i', $document['project']['website'])) {
                $meta->addLink($document['project']['website'], $document['project']['website'], ['size' => 8.5, 'color' => '525866']);
            } else {
                $meta->addText($document['project']['website'], ['size' => 8.5, 'color' => '525866']);
            }
            $meta->addText("\t");
        }
        $meta->addText($document['labels']['generated'].': '.$document['generated']['date'], ['size' => 8.5, 'color' => '525866']);
        foreach ($document['sections'] as $index => $group) {
            $heading = $section->addTextRun($index > 0 && $document['presentation']['pageBreakBySection'] ? 'SectionStart' : 'Heading1');
            $heading->addText($group['number'].'. ', ['bold' => true, 'size' => 12.5, 'color' => ltrim($brand['color'] ?? '#344054', '#')]);
            $heading->addText($group['name'], ['bold' => true, 'size' => 12.5, 'color' => '000000']);
            foreach ($group['tasks'] as $taskIndex => $task) {
                $paragraphs = [];
                foreach (array_filter($task['content'], fn (array $field): bool => $field['block']) as $field) {
                    $lines = preg_split('/\R/u', $field['value']);
                    foreach ($lines as $lineIndex => $line) {
                        $paragraphs[] = ['label' => $lineIndex === 0 && ($field['key'] ?? '') !== 'description' ? $field['label'] : '', 'value' => $line, 'font' => [], 'after' => $lineIndex === count($lines) - 1 ? 40 : 0];
                    }
                }
                if ($task['attachments']) {
                    $paragraphs[] = ['label' => $document['labels']['attachments'], 'value' => implode(' · ', $task['attachments']), 'font' => ['size' => 8.5], 'after' => 40];
                }
                if ($task['metadata'] !== '') {
                    $paragraphs[] = ['label' => '', 'value' => $task['metadata'], 'font' => ['size' => 8.5, 'color' => '525866'], 'after' => 0];
                }
                $original = implode("\n", [$task['title'], ...array_column(array_filter($task['content'], fn ($field) => $field['block']), 'value'), ...$task['attachments'], $task['metadata']]);
                $compact = $this->compact($original);
                $section->addText($task['number'].' '.$task['title'], ['size' => 10.5, 'bold' => true, 'color' => '000000'], ['outlineLevel' => 1, 'spaceBefore' => $taskIndex ? 160 : 0, 'spaceAfter' => 60, 'keepNext' => $paragraphs !== [], 'keepLines' => true]);
                foreach ($paragraphs as $paragraphIndex => $paragraph) {
                    $run = $section->addTextRun(['spaceAfter' => $paragraph['after'], 'keepNext' => $compact && $paragraphIndex < count($paragraphs) - 1, 'keepLines' => $compact || $this->compact($paragraph['value'], 350), 'widowControl' => true]);
                    if ($paragraph['label'] !== '') {
                        $run->addText($paragraph['label'].': ', array_replace($paragraph['font'], ['bold' => true]));
                    }
                    $run->addText($paragraph['value'], $paragraph['font']);
                }
            }
        }
        $section->addText($document['labels']['summary'], ['bold' => true, 'size' => 12, 'color' => '000000'], ['spaceBefore' => 240, 'spaceAfter' => 100, 'keepNext' => true]);
        $summary = $section->addTable($this->tableStyle());
        foreach ($document['summary'] as $index => $row) {
            $summary->addRow(null, ['cantSplit' => true]);
            $keepNext = $index < count($document['summary']) - 1;
            $cell = $summary->addCell(7378, ['noWrap' => false]);
            $cell->addText($row['label'], ['size' => 9.5], ['spaceAfter' => 40, 'keepNext' => $keepNext || $row['coverage'] !== null]);
            if ($row['coverage']) {
                $cell->addText($row['coverage'], ['size' => 8, 'color' => '525866'], ['spaceAfter' => 40, 'keepNext' => $keepNext]);
            }
            $summary->addCell(2600, ['noWrap' => false])->addText($row['value'], ['bold' => true, 'size' => 9.5], ['alignment' => 'right', 'spaceAfter' => 40, 'keepNext' => $keepNext]);
        }
        if (! empty($brand['business'])) {
            $section->addText($brand['businessTitle'], ['size' => 12, 'bold' => true, 'color' => '000000'], ['spaceBefore' => 240, 'spaceAfter' => 100, 'keepNext' => true]);
            $left = $right = $notes = [];
            foreach ($brand['business'] as $index => $row) {
                if (($row['key'] ?? '') === 'note') {
                    $notes[] = $row;
                } elseif (in_array($row['key'] ?? '', ['legalName', 'payee', 'taxId'], true) || (! isset($row['key']) && $index % 2 === 0)) {
                    $left[] = $row;
                } else {
                    $right[] = $row;
                }
            }
            $compact = $this->compact(implode("\n", array_map(fn ($row) => $row['label'].$row['value'], $brand['business'])), 700);
            $table = $section->addTable($this->tableStyle());
            for ($index = 0; $index < max(count($left), count($right)); $index++) {
                $table->addRow(null, ['cantSplit' => $compact]);
                foreach ([$left, $right] as $columnIndex => $column) {
                    $cell = $table->addCell(4989, ['noWrap' => false]);
                    if (isset($column[$index])) {
                        $run = $cell->addTextRun(['indentation' => ['right' => $columnIndex === 0 ? 360 : 0], 'spaceAfter' => 60, 'keepNext' => $compact && ($index < max(count($left), count($right)) - 1 || $notes !== []), 'widowControl' => true]);
                        $run->addText($column[$index]['label'].': ', ['bold' => true, 'size' => 8.5]);
                        $run->addText($column[$index]['value'], ['size' => 8.5]);
                    }
                }
            }
            foreach ($notes as $row) {
                $run = $section->addTextRun(['spaceAfter' => 40, 'widowControl' => true]);
                $run->addText($row['label'].': ', ['bold' => true, 'size' => 8.5]);
                $run->addText($row['value'], ['size' => 8.5]);
            }
        }
        $temporary = tempnam(sys_get_temp_dir(), 'projectbrief-docx-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create temporary document.');
        }
        try {
            IOFactory::createWriter($word, 'Word2007')->save($temporary);
            if ($cjk) {
                ExportFonts::embedDocx($temporary);
            }
            $bytes = file_get_contents($temporary);
            if ($bytes === false) {
                throw new RuntimeException('Cannot read generated document.');
            }

            return $bytes;
        } finally {
            unlink($temporary);
        }
    }
}
