<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function render(string $view, array $data = [], string $layout = 'layouts/app'): string
    {
        $viewFile = $this->resolve($view);
        $layoutFile = $this->resolve($layout);

        $content = $this->capture($viewFile, $data);

        return $this->capture($layoutFile, [...$data, 'content' => $content]);
    }

    private function resolve(string $view): string
    {
        $file = $this->basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $view) . '.php';
        $realBase = realpath($this->basePath);
        $realFile = realpath($file);

        if ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('View not found: ' . $view);
        }

        return $realFile;
    }

    private function capture(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();

        try {
            require $file;
            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }
}
