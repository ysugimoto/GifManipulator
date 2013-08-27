<?php

$base = realpath(dirname(__FILE__) . '/../');
require_once($base . '/lib/GifManipulator.php');

$gif = GifManipulator::createFromFile($base . '/images/sample_sliced_1.gif');
$gif->addImage(GifManipulator::createFromFile($base . '/images/sample_sliced_2.gif'));
$gif->addImage(GifManipulator::createFromFile($base . '/images/sample_sliced_3.gif'));

$gif->setAnimation(100);
$gif->save($base . '/images/sample_generated.gif');
