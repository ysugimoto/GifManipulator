<?php

$base = realpath(dirname(__FILE__) . '/../');
require_once($base . '/lib/GifManipulator.php');

$gif = GifManipulator::createFromFile($base . '/images/sample.gif');
$gif->dump();
