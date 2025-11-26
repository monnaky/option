<?php

if (empty($_POST["text"])) die("failed... text not specified...");

$text = $_POST["text"];

$file = fopen("getSignal.txt", "w") or die("failed... unable to open file");

fwrite($file, $text);

fclose($file);


<<<<<<< HEAD
?>
=======
?>
>>>>>>> b079ae2bce3f692310bb9df3e6a7b23f4b34965c
