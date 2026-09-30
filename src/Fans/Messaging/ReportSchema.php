<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;
final class ReportSchema
{
    public const OPTION='faluss_fans_message_reports_schema_version';
    public static function ready(): bool
    { return get_option(self::OPTION)==='1' && MessageSchema::verify('reports') && MessageSchema::verify('report_events') && MessageSchema::verify('legal_holds'); }
    public static function installOrVerify(): bool
    { return MessageSchema::installGroup(['reports','report_events','legal_holds'],self::OPTION) && self::ready(); }
}
