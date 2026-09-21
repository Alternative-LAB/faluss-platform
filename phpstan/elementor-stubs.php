<?php

declare(strict_types=1);

namespace Elementor;

abstract class Widget_Base
{
    /** @param array<string, mixed> $arguments */
    protected function start_controls_section(string $id, array $arguments = []): void
    {
    }

    /** @param array<string, mixed> $arguments */
    protected function add_control(string $id, array $arguments = []): void
    {
    }

    protected function end_controls_section(): void
    {
    }

    protected function start_controls_tabs(string $id): void
    {
    }

    /** @param array<string, mixed> $arguments */
    protected function start_controls_tab(string $id, array $arguments = []): void
    {
    }

    protected function end_controls_tab(): void
    {
    }

    protected function end_controls_tabs(): void
    {
    }

    /** @param array<string, mixed> $arguments */
    protected function add_group_control(string $type, array $arguments = []): void
    {
    }

    /** @param array<string, mixed> $arguments */
    protected function add_responsive_control(string $id, array $arguments = []): void
    {
    }

    /** @return array<string, mixed> */
    protected function get_settings_for_display(): array
    {
        return [];
    }
}

final class Controls_Manager
{
    public const TEXT = 'text';
    public const URL = 'url';
    public const TAB_STYLE = 'style';
    public const COLOR = 'color';
    public const DIMENSIONS = 'dimensions';
}

final class Group_Control_Typography
{
    public static function get_type(): string
    {
        return 'typography';
    }
}

final class Group_Control_Border
{
    public static function get_type(): string
    {
        return 'border';
    }
}

final class Group_Control_Box_Shadow
{
    public static function get_type(): string
    {
        return 'box-shadow';
    }
}
