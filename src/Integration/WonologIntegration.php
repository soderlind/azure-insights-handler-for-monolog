<?php
declare(strict_types=1);
namespace AzureInsightsWonolog\Integration;

use AzureInsightsWonolog\Handler\AzureInsightsHandler;

/**
 * Encapsulates Wonolog (v3) integration for pushing our handler.
 */
class WonologIntegration {
    private AzureInsightsHandler $handler;

    public function __construct( AzureInsightsHandler $handler ) {
        $this->handler = $handler;
    }

    /**
     * Attempt to attach handler to the default Wonolog logger.
     * No-op if Wonolog is absent.
     */
    public function attach(): void {
        if ( ! function_exists( '\\Inpsyde\\Wonolog\\makeLogger' ) ) {
            return; // Wonolog not loaded.
        }
        try {
            $logger = \Inpsyde\Wonolog\makeLogger();
            if ( $logger instanceof \Monolog\Logger ) {
                // Avoid duplicate push if already present.
                foreach ( $logger->getHandlers() as $existing ) {
                    if ( $existing === $this->handler ) {
                        return;
                    }
                }
                $logger->pushHandler( $this->handler );
            }
        } catch ( \Throwable $e ) {
            // Swallow – logging integration failure should not break site.
        }
    }
}
