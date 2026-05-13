<?php

declare(strict_types=1);

namespace SecurePress\Core\Headers;

/**
 * Builds a populated {@see HeaderRegistry} from {@see SecurityHeadersOptions}.
 *
 * Centralizing this here lets the dispatcher and the middleware share the same
 * configuration without each duplicating wiring.
 */
final class HeaderRegistryFactory
{
    public function __construct(
        private readonly SecurityHeadersOptions $options,
    ) {
    }

    public function make(): HeaderRegistry
    {
        $registry = new HeaderRegistry();

        // The master switch wins. When it's off we return an empty registry so
        // the dispatcher emits nothing — far cheaper than constructing each
        // header object only to never emit it. The per-header `enabled` flags
        // stay intact in storage so flipping the master back on restores the
        // previous configuration as-is.
        if (!$this->options->isEnabled()) {
            return $registry;
        }

        $config = $this->options->all();

        $registry->register(new HstsHeader(
            (bool) $config['hsts']['enabled'],
            (int) $config['hsts']['max_age'],
            (bool) $config['hsts']['include_subdomains'],
            (bool) $config['hsts']['preload'],
        ));

        $registry->register(new CspHeader(
            (bool) $config['csp']['enabled'],
            (string) $config['csp']['policy'],
            (bool) $config['csp']['report_only'],
        ));

        $registry->register(new XFrameOptionsHeader(
            (bool) $config['x_frame_options']['enabled'],
            (string) $config['x_frame_options']['value'],
        ));

        $registry->register(new ReferrerPolicyHeader(
            (bool) $config['referrer_policy']['enabled'],
            (string) $config['referrer_policy']['policy'],
        ));

        $registry->register(new PermissionsPolicyHeader(
            (bool) $config['permissions_policy']['enabled'],
            (string) $config['permissions_policy']['policy'],
        ));

        $registry->register(new XContentTypeOptionsHeader(
            (bool) $config['x_content_type_options']['enabled'],
        ));

        return $registry;
    }
}
