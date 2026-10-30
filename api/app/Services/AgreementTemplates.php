<?php

namespace App\Services;

class AgreementTemplates
{
    public function texts(): array
    {
        $texts = [];
        foreach (WorkspaceAgreements::LOCALES as $locale) {
            $source = trans('agreements.template', [], $locale);
            $blocks = [['type' => 'heading', 'content' => [['text' => $source[0]]]], ['type' => 'paragraph', 'content' => [['text' => $source[1]]]]];
            foreach (array_slice($source, 2) as $index => [$heading, $body]) {
                $blocks[] = ['type' => 'subheading', 'content' => [['text' => ($index + 1).'. '.$heading]]];
                $blocks[] = ['type' => 'paragraph', 'content' => [['text' => $body]]];
            }
            $texts[$locale] = $blocks;
        }

        return $texts;
    }

    public function defaults(): array
    {
        return ['enabled' => false, 'draft' => ['date' => now()->toDateString(), 'texts' => $this->texts(), 'enabledLocales' => ['ru'], 'defaultLocale' => 'ru'], 'published' => null];
    }
}
