<?php

namespace Blockstudio;

final class Virtual_Routes
{
    public const PRIORITY = 4;

    /**
     * Register a virtual route that renders a whole document inside
     * Blockstudio's output buffer.
     *
     * @param callable(mixed, string): (string|null|void) $render
     * @param array{
     *   prefix?: bool,
     *   match?: callable(string): mixed,
     *   title?: string|\Closure(mixed): string,
     *   urls?: list<string>|\Closure(): list<string>,
     *   status?: int
     * } $options
     */
    public static function register(string $path, callable $render, array $options = []): void {}

    public static function unregister(string $path): void {}

    /**
     * @return array{path: string, value: mixed, rest: string}|null
     */
    public static function match(string $request): ?array {}

    /**
     * @return list<string>
     */
    public static function urls(): array {}
}
