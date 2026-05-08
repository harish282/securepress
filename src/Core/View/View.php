<?php

declare(strict_types=1);

namespace SecurePress\Core\View;

use RuntimeException;

final class View
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $view, array $data = []): void
    {
        echo $this->renderToString($view, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderToString(string $view, array $data = []): string
    {
        $viewPath = $this->resolveViewPath($view);

        ob_start();
        extract($data, EXTR_SKIP);
        require $viewPath;

        $output = ob_get_clean();
        if (!is_string($output)) {
            return '';
        }

        return $output;
    }

    private function resolveViewPath(string $view): string
    {
        $relative = str_replace('.', '/', trim($view));
        $path = rtrim($this->basePath, '/') . '/' . $relative . '.php';

        if (!is_readable($path)) {
            throw new RuntimeException(sprintf('View "%s" was not found.', $view));
        }

        return $path;
    }
}
