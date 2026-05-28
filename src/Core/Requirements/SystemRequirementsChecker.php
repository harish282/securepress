<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Requirements;

use NiyiGuard\Core\Config\Config;
use NiyiGuard\Core\Logging\LoggerInterface;

final class SystemRequirementsChecker
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function passes(): bool
    {
        $errors = $this->errors();

        if ($errors !== []) {
            $this->logger->error('System requirements check failed.', ['errors' => $errors]);

            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        global $wp_version;

        $errors = [];
        $minPhp = (string) $this->config->get('requirements.php', '8.2.0');
        $minWp = (string) $this->config->get('requirements.wordpress', '6.4');

        if (version_compare(PHP_VERSION, $minPhp, '<')) {
            $errors[] = sprintf('NiyiGuard requires PHP %s or higher.', $minPhp);
        }

        if (!isset($wp_version) || version_compare((string) $wp_version, $minWp, '<')) {
            $errors[] = sprintf('NiyiGuard requires WordPress %s or higher.', $minWp);
        }

        return $errors;
    }
}
