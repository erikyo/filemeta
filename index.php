<!doctype html>
<html lang="EN">
<head>
    <meta charset="utf-8">
    <title></title>
    <meta name="description" content="">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
      body {
        font-family: monospace;
      }
      body > div {
        max-width: 1200px;
        margin: auto;
      }
      div:nth-of-type(2n) {
        background: #f3f3f3;
      }
      pre {
        padding: 20px;
        background: #333;
        color: white;
        font-size: 10px;
        line-height: 1.3;
        white-space: pre-wrap;
      }
      img,
      video,
      audio {
        width: 200px;
        height: 200px;
        object-fit: contain;
        padding: 10px;
        margin: 10px 0;
        border: 1px solid #ccc;
      }
      span {
        background: #ff5722;
        padding: 3px 6px;
        border-radius: 3px;
        color: white;
      }
      span.image{
        background: #00bcd4;
      }
      span.video{
        background: #8bc34a;
      }
      span.audio{
        background: #3f51b5;
      }
    </style>
</head>

<body>

<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once('filemeta.php');

foreach (array_slice(scandir(__DIR__.'/media'), 2) as $media) {

    $filepath = __DIR__.'/media/'.$media;

    $mime = explode("/",mime_content_type('./media/'.$media));

    echo '<div class="media-wrap">';

    switch ($mime[0]) {
        case "image":
            echo "<img src=\"media/$media\" />";
            break;
        case "video":
            echo "<video controls><source src=\"media/$media\" /></video>";
            break;
        case "audio":
            echo "<audio controls><source src=\"media/$media\" /></audio>";
            break;
    }

    printf("<p>%s <span class='%s'>%s %s</span> (%s)</p>", $media, $mime[0], $mime[0], $mime[1], $filepath);

    echo "<pre>";
    $image_blob = new Filemeta($filepath);
    print_r($image_blob->extract_meta());
    echo '</pre></div><br/>';
}
?>
</body>
</html>
