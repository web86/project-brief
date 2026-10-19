<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Style\ListItem;
use RuntimeException;

class DocxProjectExporter
{
    public function render(array $document): string
    {
        Settings::setOutputEscapingEnabled(true);
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(11);
        $word->setDefaultParagraphStyle(['spaceAfter' => 80, 'lineHeight' => 1.15]);
        $word->addTitleStyle(0, ['size' => 22, 'bold' => true], ['spaceAfter' => 200]);
        $word->addTitleStyle(1, ['size' => 14, 'bold' => true], ['spaceBefore' => 200, 'spaceAfter' => 100, 'keepNext' => true]);
        $word->addTitleStyle(2, ['size' => 12, 'bold' => true], ['spaceBefore' => 140, 'spaceAfter' => 60, 'keepNext' => true]);
        $word->addParagraphStyle('SectionStart', ['basedOn' => 'Heading1', 'outlineLevel' => 0, 'pageBreakBefore' => true, 'keepNext' => true]);
        $section = $word->addSection(['marginTop' => 1247, 'marginBottom' => 1417, 'marginLeft' => 1134, 'marginRight' => 1134]);
        $section->addFooter()->addText($document['labels']['footer'].' · '.$document['generated']['date'], ['size' => 8, 'color' => '667085']);
        $section->addTitle($document['title'], 0);
        $section->addText($document['labels']['project'].': '.$document['project']['name']);
        if ($document['project']['website']) {
            $section->addText($document['labels']['website'].': '.$document['project']['website']);
        }
        $section->addText($document['labels']['generated'].': '.$document['generated']['date']);
        foreach ($document['sections'] as $index => $group) {
            if ($index > 0 && $document['presentation']['pageBreakBySection']) {
                $section->addText($group['number'].'. '.$group['name'], null, 'SectionStart');
            } else {
                $section->addTitle($group['number'].'. '.$group['name'], 1);
            }
            foreach ($group['tasks'] as $task) {
                $section->addTitle($task['number'].' '.$task['title'], 2);
                $blocks = array_values(array_filter($task['content'], fn (array $field): bool => $field['block']));
                foreach ($blocks as $blockIndex => $field) {
                    $section->addText($field['label'], ['bold' => true, 'size' => 10], ['keepNext' => true, 'spaceBefore' => 40, 'spaceAfter' => 20]);
                    $paragraphs = preg_split('/\R/u', $field['value']);
                    foreach ($paragraphs as $paragraphIndex => $paragraph) {
                        $keepNext = $blockIndex === count($blocks) - 1 && $paragraphIndex === count($paragraphs) - 1 && ($task['attachments'] || $task['metadata'] !== '');
                        $section->addText($paragraph, null, ['keepNext' => $keepNext]);
                    }
                }
                if ($task['attachments']) {
                    $section->addText($document['labels']['attachments'], ['bold' => true, 'size' => 10], ['keepNext' => true, 'spaceAfter' => 20]);
                    foreach ($task['attachments'] as $attachmentIndex => $filename) {
                        $keepNext = $attachmentIndex === count($task['attachments']) - 1 && $task['metadata'] !== '';
                        $section->addListItem($filename, 0, ['size' => 10], ['listType' => ListItem::TYPE_BULLET_FILLED], ['spaceAfter' => 20, 'keepNext' => $keepNext]);
                    }
                }
                if ($task['metadata'] !== '') {
                    $section->addText($task['metadata'], ['size' => 9, 'color' => '586174'], ['spaceBefore' => 40, 'spaceAfter' => 100]);
                }
            }
        }
        $section->addText($document['labels']['summary'], ['bold' => true, 'size' => 12], ['spaceBefore' => 200, 'spaceAfter' => 80, 'keepNext' => true]);
        foreach ($document['summary'] as $index => $row) {
            $keepNext = $row['coverage'] !== null || $index < count($document['summary']) - 1;
            $section->addText($row['label'].': '.$row['value'], ['size' => 10], ['spaceAfter' => 40, 'keepNext' => $keepNext]);
            if ($row['coverage']) {
                $section->addText($row['coverage'], ['size' => 9, 'color' => '586174'], ['spaceAfter' => 60, 'keepNext' => $index < count($document['summary']) - 1]);
            }
        }
        $temporary = tempnam(sys_get_temp_dir(), 'projectbrief-docx-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create temporary document.');
        }
        try {
            IOFactory::createWriter($word, 'Word2007')->save($temporary);
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
