<?php

namespace Blockstudio;

final class Blade_Renderer
{
    public static function available(): bool {}

    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $directory, string $view, array $data = []): ?string {}
}
