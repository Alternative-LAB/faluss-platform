<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

/** Metadata and temporary text snapshots; never a PF ledger or parallel inbox. */
final class MessageAutomationSchema
{
    public const OPTION='faluss_fans_message_automation_schema_version';
    public static function ready(): bool
    {
        return get_option(self::OPTION)==='1' && MessageSchema::verify('automatic_settings')
            && MessageSchema::verify('automatic_events') && MessageSchema::verify('automatic_contacts');
    }
    public static function installOrVerify(): bool
    { return MessageSchema::installGroup(['automatic_settings','automatic_events','automatic_contacts'],self::OPTION); }
}
