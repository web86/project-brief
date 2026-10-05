<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class SafeAttachment implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid() || ! is_readable($value->getPathname())) {
            $fail('Не удалось прочитать файл.');

            return;
        }
        $extension = strtolower($value->getClientOriginalExtension());
        $mime = $value->getMimeType();
        $allowed = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'txt' => ['text/plain'], 'zip' => ['application/zip', 'application/x-zip-compressed']];
        if (! isset($allowed[$extension]) || ! in_array($mime, $allowed[$extension], true)) {
            $fail('Допустимы JPG, PNG, WEBP, PDF, DOC, DOCX, TXT и ZIP. Содержимое должно соответствовать типу файла.');

            return;
        }
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp']) && @getimagesize($value->getPathname()) === false) {
            $fail('Изображение повреждено.');
        }
        if (in_array($extension, ['docx', 'zip'])) {
            $zip = new \ZipArchive;
            $opened = $zip->open($value->getPathname());
            if ($opened !== true) {
                $fail('Архив повреждён.');

                return;
            }
            if ($extension === 'docx' && ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('word/document.xml') === false || $zip->locateName('word/vbaProject.bin') !== false)) {
                $fail('Документ DOCX повреждён или содержит макросы.');
            }
            $zip->close();
        }
    }
}
