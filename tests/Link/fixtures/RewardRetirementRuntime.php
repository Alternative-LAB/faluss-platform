<?php

declare(strict_types=1);

namespace Elementor {
    // Only the inheritance surface is simulated; this is not an Elementor runtime.
    abstract class Widget_Base
    {
        public function __construct(public array $data = [], array $args = []) {}
        public function print_element() { echo '<div>base wrapper</div>'; }
        public function show_in_panel() { return true; }
        public function hide_on_search() { return false; }
    }
}

namespace {
    final class RewardRetiredResponse extends \RuntimeException
    {
        public function __construct(public array $data, public int $status) { parent::__construct(); }
    }
    function wp_send_json_error($data = null, $status = null): never
    {
        throw new RewardRetiredResponse($data, $status);
    }
    final class WP_Error
    {
        public function __construct(public string $code) {}
    }
    final class Token_Engine_Connector_Service
    {
        public static int $rewardCalls = 0;
        public static array $rights = [];
        public static bool $allowed = true;
        public static function daily_reward_offer(): array
        {
            ++self::$rewardCalls;
            return ['state' => 'available', 'amount' => 20, 'unit' => 'ALB'];
        }
        public static function daily_reward_status_for_current_subject(): array { return self::daily_reward_offer(); }
        public static function claim_daily_reward_for_current_subject(): array
        {
            ++self::$rewardCalls;
            return ['state' => 'granted'];
        }
        public static function subject_has_entitlement(string $id, string $code): bool
        {
            self::$rights[] = [$id, $code];
            return self::$allowed;
        }
        public static function entitlement_definitions(): array
        {
            return [['code' => 'teaser.access', 'label' => 'Teaser']];
        }
    }
    final class Faluss_Identity_Schema
    {
        public static function get_status(): array { return ['ready' => true]; }
    }
    final class Faluss_Identity_Registry
    {
        public static function get_active_for_wp_user(int $id): string { return '11111111-1111-4111-8111-111111111111'; }
    }
}
