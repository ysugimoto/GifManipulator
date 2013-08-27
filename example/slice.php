<?php

$base = realpath(dirname(__FILE__) . '/../');
require_once($base . '/lib/GifManipulator.php');

$gif = GifManipulator::createFromFile($base . '/images/sample.gif');

$index = 0;
while ( $img = $gif->slice() ) {
	$img->save($base . '/images/sample_sliced_' . ++$index . '.gif');
}
