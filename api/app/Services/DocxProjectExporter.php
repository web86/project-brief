<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use RuntimeException;

class DocxProjectExporter
{
    public function render(array $document): string
    {
        Settings::setOutputEscapingEnabled(true);
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(11);
        $word->setDefaultParagraphStyle(['spaceAfter' => 120]);
        $word->addTitleStyle(0, ['size' => 22, 'bold' => true], ['spaceAfter' => 320]);
        $word->addTitleStyle(1, ['size' => 16, 'bold' => true], ['spaceBefore' => 300, 'keepNext' => true]);
        $word->addTitleStyle(2, ['size' => 13, 'bold' => true], ['spaceBefore' => 200, 'keepNext' => true]);
        $section = $word->addSection(['marginTop' => 1247, 'marginBottom' => 1417, 'marginLeft' => 1134, 'marginRight' => 1134]);
        $section->addFooter()->addText($document['labels']['footer'].' · '.$document['generated']['date'], ['size' => 9, 'color' => '667085']);
        $section->addTitle($document['labels']['title'], 0);
        $section->addText($document['labels']['project'].': '.$document['project']['name']);
        if ($document['project']['website']) {
            $section->addText($document['labels']['website'].': '.$document['project']['website']);
        }
        $section->addText($document['labels']['generated'].': '.$document['generated']['date']);
        foreach ($document['sections'] as $group) {
            $section->addTitle($group['number'].'. '.$group['name'], 1);
            foreach ($group['tasks'] as $task) {
                $section->addTitle($task['number'].' '.$task['title'], 2);
                foreach ($task['content'] as $field) {
                    if (! $field['block']) {
                        $section->addText($field['label'].': '.$field['value']);

                        continue;
                    }
                    $section->addText($field['label'], ['bold' => true], ['keepNext' => true]);
                    foreach (preg_split('/\R/u', $field['value']) as $paragraph) {
                        $section->addText($paragraph);
                    }
                }
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
