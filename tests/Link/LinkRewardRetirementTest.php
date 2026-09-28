<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LinkRewardRetirementTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/WordPressStubs.php';
        require_once __DIR__ . '/fixtures/RewardRetirementRuntime.php';
        link_test_reset();
        (new LinkModule())->boot();
    }

    public function testSavedShortcodeAndWidgetAreEmptyWithoutRewardAssetsOrCalls(): void
    {
        $attributes = ['show_balance' => 'yes', 'hide_unavailable' => 'no', 'claim_label' => '20 ALB'];
        foreach ([false, true] as $loggedIn) {
            $GLOBALS['link_test_logged_in'] = $loggedIn;
            self::assertSame('', ($GLOBALS['link_test_shortcodes']['faluss_link_daily_reward'])($attributes));
            self::assertSame('', \Faluss_Link::render_daily_reward($attributes));
        }
        $manager = new class {
            public array $widgets = [];
            public function register($widget): void { $this->widgets[$widget->get_name()] = $widget; }
        };
        \Faluss_Link::widgets($manager);
        self::assertArrayHasKey('faluss_link_daily_reward', $manager->widgets);
        $widget = new \Faluss_Link_Daily_Reward_Widget(['id' => 'old-placement', 'settings' => $attributes + ['_background_color' => '#ff0000', '_margin' => ['top' => 50]]]);
        self::assertFalse($widget->show_in_panel());
        self::assertTrue($widget->hide_on_search());
        self::assertSame([], $widget->get_style_depends());
        self::assertSame([], $widget->get_script_depends());
        ob_start();
        $widget->print_element();
        (new \ReflectionMethod($widget, 'render'))->invoke($widget);
        (new \ReflectionMethod($widget, 'register_controls'))->invoke($widget);
        self::assertSame('', ob_get_clean());
        \Faluss_Link::assets();
        self::assertArrayNotHasKey('faluss-link-reward', $GLOBALS['link_test_styles']);
        self::assertArrayNotHasKey('faluss-link-reward', $GLOBALS['link_test_scripts']);
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/assets/link/js/faluss-link-reward.js');
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/assets/link/css/faluss-link-reward.css');
        self::assertSame(0, \Token_Engine_Connector_Service::$rewardCalls);
    }

    public function testOldAjaxAndDirectAdapterCallsNeverReachACreditCapableConnector(): void
    {
        $handler = $GLOBALS['link_test_actions']['wp_ajax_faluss_link_daily_reward_claim'][0]['callback'];
        self::assertArrayNotHasKey('wp_ajax_nopriv_faluss_link_daily_reward_claim', $GLOBALS['link_test_actions']);
        foreach ([false, true] as $loggedIn) {
            foreach ([false, true] as $validNonce) {
                $GLOBALS['link_test_logged_in'] = $loggedIn;
                $GLOBALS['link_test_nonce_valid'] = $validNonce;
                $_POST = ['nonce' => $validNonce ? 'valid' : 'invalid', 'amount' => 9999, 'rule' => 'daily_reward'];
                try {
                    $handler();
                    self::fail('The retired action must terminate.');
                } catch (\RewardRetiredResponse $response) {
                    self::assertSame(410, $response->status);
                    self::assertSame(['code' => 'faluss_link_daily_reward_retired'], $response->data);
                }
            }
        }
        foreach (['dailyRewardOffer', 'dailyRewardStatusForCurrentSubject', 'claimDailyRewardForCurrentSubject'] as $method) {
            self::assertSame('faluss_link_daily_reward_retired', LinkTokenEngineConnectorAdapter::$method()->code);
        }
        self::assertSame(0, \Token_Engine_Connector_Service::$rewardCalls);
        self::assertSame([], $GLOBALS['link_test_option_updates']);
    }

    public function testTeaserAndThemeEntitlementsStillReadTheConnectorAndFailClosed(): void
    {
        $decision = new \ReflectionMethod('Faluss_Link', 'teaser_access_decision');
        $block = ['access_mode' => 'entitlement', 'entitlement_code' => 'teaser.access'];
        $owner = '22222222-2222-4222-8222-222222222222';
        self::assertSame(['visible' => false, 'login_required' => true], $decision->invoke(null, $block, $owner));
        $GLOBALS['link_test_logged_in'] = true;
        $GLOBALS['link_test_user_id'] = 17;
        foreach ([true, false] as $allowed) {
            \Token_Engine_Connector_Service::$allowed = $allowed;
            self::assertSame(['visible' => $allowed, 'login_required' => false], $decision->invoke(null, $block, $owner));
            self::assertSame($allowed, LinkTokenEngineConnectorAdapter::subjectHasEntitlement('11111111-1111-4111-8111-111111111111', 'studio.theme.plus'));
        }
        self::assertCount(4, \Token_Engine_Connector_Service::$rights);
        self::assertSame(['11111111-1111-4111-8111-111111111111', 'teaser.access'], \Token_Engine_Connector_Service::$rights[0]);
        self::assertSame([['code' => 'teaser.access', 'label' => 'Teaser']], LinkTokenEngineConnectorAdapter::entitlementDefinitions());
        self::assertSame(0, \Token_Engine_Connector_Service::$rewardCalls);
    }
}
