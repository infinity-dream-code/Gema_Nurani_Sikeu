<?php

/**
 * Define global ZipArchive when ext-zip is not installed.
 * Needed to read .xlsx via PhpSpreadsheet on servers without php-zip.
 */
require_once __DIR__ . '/PurePhpZip.php';

if (!class_exists('ZipArchive', false)) {
    class ZipArchive extends \App\Support\PurePhpZip
    {
    }
}
