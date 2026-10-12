<?php

$title = '';

if ( $a['cta']['title'] !== null ) {
	$title = trim( $a['cta']['title'] );
}

if ( isset( $a['cta']['target'] ) ) {
	$title .= strtolower( $a['cta']['target'] );
}

$first = $a['items'][0]['label'] ?? '';

echo $title . trim( $first ) . trim( $a['label'] );
