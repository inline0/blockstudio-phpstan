<?php

$label = $a['label'];

if ( $a['featured'] && $isEditor ) {
	$label .= (string) count( $block );
}

echo $label . $undefined_value;
