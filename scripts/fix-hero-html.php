<?php
$path = dirname( __DIR__ ) . '/paxdesign-toolbar/assets/js/dock.js';
$c    = file_get_contents( $path );
$c    = str_replace( '<motion.div', '<div', $c );
$c    = str_replace( '</motion.div>', '</div>', $c );
file_put_contents( $path, $c );
echo "ok\n";
