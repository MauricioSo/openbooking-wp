<?php


declare( strict_types=1 );
namespace OpenBooking\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Carga automaticamente las clases del plugin siguiendo el estandar de namespaces.
 */

class Autoloader {

    private string $namespace_prefix = 'OpenBooking\\';
    private string $base_dir;

    public function __construct() {
        $this->base_dir = OBWP_PLUGIN_DIR . 'src' . DIRECTORY_SEPARATOR;
    }

    public function register(): void {
        spl_autoload_register( [ $this, 'autoload' ] );
    }

    public function autoload( string $class ): void {
        if ( 0 !== strpos( $class, $this->namespace_prefix ) ) {
            return;
        }

        $relative_class = substr( $class, strlen( $this->namespace_prefix ) );
        $file = $this->base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class ) . '.php';

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}
