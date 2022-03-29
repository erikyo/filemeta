<?php
echo '<pre>';

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('filemeta.php');

echo '<br/><br/><br/>';

$image_blob = new Filemeta(__DIR__.'/arrow.webp');
print_r($image_blob->extract_meta());
echo '<br/><br/><br/>';

$image_blob2 = new Filemeta(__DIR__.'/arrow.jpg');
print_r($image_blob2->extract_meta());
echo '<br/><br/><br/>';

$image_blob2 = new Filemeta(__DIR__.'/icc.jpg');
print_r($image_blob2->extract_meta());
echo '<br/><br/><br/>';

$image_blob2 = new Filemeta(__DIR__.'/Nikon_D70.jpg');
print_r($image_blob2->extract_meta());
echo '<br/><br/><br/>';


$image_blob2 = new Filemeta(__DIR__.'/thumbs-up.apng');
print_r($image_blob2->extract_meta());


echo '</pre>';