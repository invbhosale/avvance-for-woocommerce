
<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class Avvance_Logger { public static function log( $message, $context = [] ) { if ( function_exists( 'wc_get_logger' ) ) { wc_get_logger()->info( $message, [ 'source' => 'avvance', 'context' => $context ] ); } } }
