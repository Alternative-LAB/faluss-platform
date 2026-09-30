<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Core\Module;
use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use Faluss\Platform\Fans\Sso\FansSsoSchema;
use Faluss\Platform\Fans\Sso\FansSsoService;

final class MessageModule implements Module
{
    public function id(): string {return 'fans-messaging';}
    public function roles(): array {return [SiteRole::Fans];}
    public function dependencies(): array {return ['fans-creator-profiles'];}
    public function boot(): void
    {
        if(!self::privateAccessAvailable()) {return;}
        add_action('rest_api_init',[MessageRest::class,'routes']);
        \Faluss\Platform\Fans\Moderation\ModerationPanel::register();
    }
    public static function activate(): void
    {
        if(self::enabled()&&self::policyAttested()&&FansSsoService::configured()&&FansSsoSchema::ready()&&CreatorProfileSchema::ready()) {
            if(MessageSchema::installOrVerify()) {ReportSchema::installOrVerify();}
        }
    }
    private static function policyAttested(): bool
    {return defined('FALUSS_FANS_MESSAGING_POLICY_ATTESTED')&&constant('FALUSS_FANS_MESSAGING_POLICY_ATTESTED')===true;}
    public static function enabled(): bool
    {
        if (SiteRole::fromValue(defined('FALUSS_PLATFORM_ROLE')?constant('FALUSS_PLATFORM_ROLE'):null)!==SiteRole::Fans) { return false; }
        foreach (['SSO','CREATOR_PROFILES','MESSAGING'] as $flag) {
            if (!defined('FALUSS_PLATFORM_FANS_'.$flag) || constant('FALUSS_PLATFORM_FANS_'.$flag)!==true) { return false; }
        }
        return true;
    }
    public static function available(): bool
    { return self::enabled() && self::privateAccessAvailable(); }
    /** Closing new admissions must preserve existing member recourse and retention. */
    public static function privateAccessAvailable(): bool
    {
        return SiteRole::fromValue(defined('FALUSS_PLATFORM_ROLE')?constant('FALUSS_PLATFORM_ROLE'):null)===SiteRole::Fans
            && defined('FALUSS_PLATFORM_FANS_SSO')&&constant('FALUSS_PLATFORM_FANS_SSO')===true
            && defined('FALUSS_PLATFORM_FANS_CREATOR_PROFILES')&&constant('FALUSS_PLATFORM_FANS_CREATOR_PROFILES')===true
            && self::policyAttested()&&FansSsoService::configured()&&FansSsoSchema::ready()&&CreatorProfileSchema::ready()&&MessageSchema::ready()&&ReportSchema::ready();
    }
}
