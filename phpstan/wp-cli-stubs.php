<?php
class WP_CLI
{
    /** @return never */
    public static function error(string $message): void { throw new RuntimeException($message); }
    public static function log(string $message): void {}
}
