<?php

$files = [
    dirname(__DIR__).'/FileMetaTest.php',
];

foreach ($files as $file) {
    if (file_exists($file)) {
        require_once $file;
    }
}
