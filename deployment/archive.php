<?php

$root = $argv[1];
$target = $argv[2];
$zip = new ZipArchive;
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create release archive.');
}
$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
$count = 0;
foreach ($entries as $entry) {
    $name = substr($entry->getPathname(), strlen($root) + 1);
    $added = $entry->isDir() ? $zip->addEmptyDir($name) : $zip->addFile($entry->getPathname(), $name);
    if (! $added) {
        throw new RuntimeException('Cannot add release entry.');
    }
    $zip->setExternalAttributesName($entry->isDir() ? $name.'/' : $name, ZipArchive::OPSYS_UNIX, ($entry->isDir() ? 040755 : 0100644) << 16);
    $count++;
}
if (! $zip->close() || $zip->open($target, ZipArchive::CHECKCONS) !== true || $zip->numFiles !== $count) {
    throw new RuntimeException('Archive validation failed.');
}
for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = $zip->getNameIndex($index);
    if (! str_ends_with($name, '/') && ! hash_equals(hash_file('sha256', $root.'/'.$name), hash('sha256', $zip->getFromName($name)))) {
        throw new RuntimeException('Archive checksum mismatch.');
    }
}
$zip->close();
