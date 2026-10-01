<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Profiles;

use Faluss\Platform\Fans\Sso\FansSsoSchema;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CreatorProfileTest.php';

/** Failure injection model; the isolated SQL recipe exercises the actual SQL separately. */
class EditorialDb extends CreatorProfileDb
{
    public ?array $editorial = null;
    public ?array $published = null;
    public array $audit = [];
    public bool $failAudit = false;
    public bool $failCommit = false;
    public bool $lock = true;
    public bool $suppressed = false;
    public int $rate = 0;
    private ?array $snapshot = null;
    public function suppress_errors(bool $v = true): bool { $p = $this->suppressed; $this->suppressed = $v; return $p; }
    public function get_var(string $q): mixed
    {
        if (str_contains($q, 'GET_LOCK')) { return $this->lock ? 1 : 0; }
        if (str_contains($q, 'RELEASE_LOCK')) { return 1; }
        if (str_starts_with($q, 'SELECT COUNT')) { return $this->rate; }
        return parent::get_var($q);
    }
    public function get_row(string $q, mixed $output = null): ?array
    {
        if (str_starts_with($q, 'SELECT * FROM `wp_faluss_fans_editorial_approved`')) { return $this->published; }
        if (str_starts_with($q, 'SELECT * FROM `wp_faluss_fans_editorial`')) {
            return $this->editorial !== null && $this->editorial['creator_id'] === end($this->prepared)['args'][0] ? $this->editorial : null;
        }
        return parent::get_row($q, $output);
    }
    public function get_results(string $q, mixed $output = null): array
    {
        $schema = null;
        foreach (FansSsoSchema::schema() as $s) { if (str_contains($q, $s['suffix'])) { $schema = $s; } }
        if (str_contains($q, 'faluss_fans_editorial')) {
            $audit = str_contains($q, 'editorial_decisions');
            $schema = ['columns' => array_map(static fn ($t) => ['type' => $t, 'null' => false], EditorialSchema::columns($audit)),
                'indexes' => $audit ? ['PRIMARY' => [true, ['creator_id', 'revision']]]
                    : ['PRIMARY' => [true, ['creator_id']], 'state_creator' => [false, ['state', 'creator_id']]]];
            if (str_starts_with($q, 'SELECT revision,')) { return $this->audit; }
            if (str_starts_with($q, 'SELECT *')) { return $this->editorial !== null && $this->editorial['state'] === 'pending' ? [$this->editorial] : []; }
        }
        if ($schema !== null && str_starts_with($q, 'SHOW FULL COLUMNS')) {
            $rows = [];
            foreach ($schema['columns'] as $name => $c) { $rows[] = ['Field' => $name, 'Type' => $c['type'], 'Null' => $c['null'] ? 'YES' : 'NO', 'Extra' => !empty($c['auto']) ? 'auto_increment' : '']; }
            return $rows;
        }
        if ($schema !== null && str_starts_with($q, 'SHOW INDEX')) {
            $rows = [];
            foreach ($schema['indexes'] as $name => [$unique, $columns]) {
                foreach ($columns as $i => $column) { $rows[] = ['Key_name' => $name, 'Non_unique' => $unique ? '0' : '1', 'Seq_in_index' => $i + 1, 'Column_name' => $column]; }
            }
            return $rows;
        }
        return parent::get_results($q, $output);
    }
    public function query(string $q): int|false
    {
        $a = end($this->prepared)['args'] ?? [];
        if ($q === 'START TRANSACTION') { $this->snapshot = [$this->editorial, $this->audit, $this->published]; return 1; }
        if ($q === 'COMMIT') { if ($this->failCommit) { return false; } $this->snapshot = null; return 1; }
        if ($q === 'ROLLBACK') { if ($this->snapshot !== null) { [$this->editorial, $this->audit, $this->published] = $this->snapshot; $this->snapshot = null; } return 1; }
        if (str_starts_with($q, 'REPLACE INTO `wp_faluss_fans_editorial_approved`')) { $this->published = $this->editorial; return 1; }
        if (str_starts_with($q, 'DELETE FROM `wp_faluss_fans_editorial_approved`')) { $this->published = null; return 1; }
        if (str_starts_with($q, 'INSERT INTO `wp_faluss_fans_editorial_decisions`')) {
            if ($this->failAudit) { return false; }
            $this->audit[] = $a; return 1;
        }
        if (str_starts_with($q, 'INSERT INTO `wp_faluss_fans_editorial`')) {
            $this->editorial = array_combine(['creator_id', 'revision', 'state', 'public_name', 'bio', 'portrait_id', 'portrait_revision'], $a); return 1;
        }
        if (str_starts_with($q, 'UPDATE `wp_faluss_fans_editorial`')) {
            if (str_contains($q, 'SET revision=')) {
                [$rev, $state, $name, $bio, $portrait, $portraitRev] = $a;
                $this->editorial = array_merge($this->editorial, ['revision' => $rev, 'state' => $state, 'public_name' => $name, 'bio' => $bio, 'portrait_id' => $portrait, 'portrait_revision' => $portraitRev]);
            } else {
                $this->editorial['state'] = $a[0]; ++$this->editorial['revision'];
                if (str_contains($q, "public_name=''")) { $this->editorial = array_merge($this->editorial, ['public_name' => '', 'bio' => '', 'portrait_id' => '', 'portrait_revision' => 0]); }
            }
            return 1;
        }
        return parent::query($q);
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EditorialTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';
    protected function setUp(): void
    {
        \Faluss\Platform\Fans\Sso\fans_sso_reset();
        define('FALUSS_PLATFORM_ROLE', 'fans');
        foreach (['SSO', 'CREATOR_PROFILES', 'EDITORIAL'] as $f) { define('FALUSS_PLATFORM_FANS_' . $f, true); }
        define('FALUSS_FANS_SSO_CLIENT_ID', 'editorial-test');
        define('FALUSS_FANS_SSO_CLIENT_SECRET', str_repeat('s', 43));
        $GLOBALS['wpdb'] = new EditorialDb();
        $GLOBALS['profile_options'] = [CreatorProfileSchema::OPTION => CreatorProfileSchema::VERSION, EditorialSchema::OPTION => EditorialSchema::VERSION];
        $GLOBALS['fans_sso_options'] = [FansSsoSchema::OPTION => FansSsoSchema::VERSION];
        $GLOBALS['profile_admin'] = false; $GLOBALS['profile_linked'] = true;
        $GLOBALS['fans_sso_logged_in'] = true; $GLOBALS['fans_sso_current_user'] = 17;
        $GLOBALS['fans_sso_users'][17] = new \WP_User(17, ['subscriber']);
        $GLOBALS['wpdb']->profile = ['creator_id' => self::ID, 'wp_user_id' => 17, 'status' => 'active', 'category' => 'arts',
            'created_at' => '2026-09-30 00:00:00', 'updated_at' => '2026-09-30 00:00:00'];
        self::assertTrue(EditorialModule::available());
    }
    private function submit(int $rev = 0): array
    {
        $row = EditorialService::submit($rev, 'Atelier de test', "Présentation de test.\nDeuxième ligne.", '', 0);
        self::assertIsArray($row); return $row;
    }
    public function testApprovalAndEveryEditControlPublicProjection(): void
    {
        self::assertSame('absent', EditorialService::own()['state']);
        $this->submit(); self::assertNull(EditorialService::publicById(self::ID));
        self::assertSame(403, EditorialService::decide(self::ID, 1, 'approve', 'allowed_editorial')->get_error_data()['status']);
        $GLOBALS['profile_admin'] = true;
        self::assertSame('approved', EditorialService::decide(self::ID, 1, 'approve', 'allowed_editorial')['state']);
        self::assertSame(['public_name', 'bio', 'revision', 'portrait'], array_keys(EditorialService::publicById(self::ID)));
        self::assertFalse(EditorialService::publicById(self::ID)['portrait']);
        $GLOBALS['profile_admin'] = false; $this->submit(2);
        self::assertSame(2, EditorialService::publicById(self::ID)['revision']);
        self::assertCount(3, $GLOBALS['wpdb']->audit);
    }

    public function testMissingLinkBlocksApprovalWithoutLosingPendingContent(): void
    {
        $this->submit(); $GLOBALS['profile_admin'] = true; $GLOBALS['profile_linked'] = false;
        $before = $GLOBALS['wpdb']->audit;
        self::assertSame('creator_link_missing', EditorialService::decide(self::ID, 1, 'approve', 'allowed_editorial')->get_error_code());
        self::assertSame('pending', $GLOBALS['wpdb']->editorial['state']);
        self::assertSame($before, $GLOBALS['wpdb']->audit);
        self::assertNull(EditorialService::publicById(self::ID));
        self::assertIsArray(EditorialService::decide(self::ID, 1, 'reject', 'needs_revision'));
    }

    public function testDraftRejectionPreservesApprovalAndOwnerCanWithdrawAfterRejection(): void
    {
        $this->submit(); $GLOBALS['profile_admin'] = true;
        EditorialService::decide(self::ID, 1, 'approve', 'allowed_editorial');
        $GLOBALS['profile_admin'] = false;
        EditorialService::submit(2, 'Proposition privée', 'Bio privée', '', 0);
        self::assertSame('Atelier de test', EditorialService::publicById(self::ID)['public_name']);
        self::assertSame('Proposition privée', EditorialService::own()['public_name']);
        $GLOBALS['profile_admin'] = true;
        EditorialService::decide(self::ID, 3, 'reject', 'needs_revision');
        self::assertSame(2, EditorialService::publicById(self::ID)['revision']);
        $GLOBALS['profile_admin'] = false;
        self::assertSame('withdrawn', EditorialService::decide(self::ID, 4, 'withdraw', '')['state']);
        self::assertNull(EditorialService::publicById(self::ID));
        self::assertNull(EditorialService::own()['published']);
    }

    public function testRevocationDuringDraftIsAdminOnlyAtomicAndRevisionBound(): void
    {
        $this->submit(); $GLOBALS['profile_admin'] = true;
        EditorialService::decide(self::ID, 1, 'approve', 'allowed_editorial');
        $GLOBALS['profile_admin'] = false; $this->submit(2);
        self::assertSame(403, EditorialService::decide(self::ID, 3, 'revoke', 'prohibited_content')->get_error_data()['status']);
        $GLOBALS['profile_admin'] = true;
        self::assertSame(409, EditorialService::decide(self::ID, 2, 'revoke', 'prohibited_content')->get_error_data()['status']);
        $GLOBALS['wpdb']->failCommit = true;
        self::assertInstanceOf(\WP_Error::class, EditorialService::decide(self::ID, 3, 'revoke', 'prohibited_content'));
        $GLOBALS['wpdb']->failCommit = false;
        self::assertSame(2, EditorialService::publicById(self::ID)['revision']);
        self::assertSame('pending', EditorialService::own()['state']);
        self::assertSame('rejected', EditorialService::decide(self::ID, 3, 'revoke', 'prohibited_content')['state']);
        self::assertNull(EditorialService::publicById(self::ID));
        self::assertSame('', EditorialService::own()['public_name']);
    }
    public function testStaleRevisionForeignOwnerAndPermissionDenied(): void
    {
        $this->submit();
        self::assertSame(409, EditorialService::submit(0, 'Autre', '', '', 0)->get_error_data()['status']);
        $GLOBALS['fans_sso_current_user'] = 18; $GLOBALS['fans_sso_users'][18] = new \WP_User(18);
        self::assertInstanceOf(\WP_Error::class, EditorialService::own());
        self::assertSame(403, EditorialService::decide(self::ID, 1, 'withdraw', 'creator_withdrawal')->get_error_data()['status']);
        self::assertInstanceOf(\WP_Error::class, EditorialService::moderation());
        self::assertInstanceOf(\WP_Error::class, EditorialService::inspect(self::ID));
        self::assertFalse(EditorialRest::member(new \WP_REST_Request()));
        self::assertFalse(EditorialRest::admin(new \WP_REST_Request([], ['X-WP-Nonce' => 'valid-nonce'])));
        $GLOBALS['profile_admin'] = true;
        self::assertTrue(EditorialRest::admin(new \WP_REST_Request([], ['X-WP-Nonce' => 'valid-nonce'])));
        self::assertFalse(EditorialRest::admin(new \WP_REST_Request()));
        self::assertSame('pending', $GLOBALS['wpdb']->editorial['state']);
    }
    public function testSuspensionHidesAndOwnerCanStillWithdraw(): void
    {
        $this->submit(); $GLOBALS['profile_admin'] = true;
        EditorialService::decide(self::ID, 1, 'approve', 'allowed_editorial');
        $GLOBALS['profile_admin'] = false; $GLOBALS['wpdb']->profile['status'] = 'suspended';
        self::assertNull(EditorialService::publicById(self::ID));
        self::assertInstanceOf(\WP_Error::class, EditorialService::submit(2, 'Autre', '', '', 0));
        self::assertSame('withdrawn', EditorialService::decide(self::ID, 2, 'withdraw', '')['state']);
        self::assertSame('', EditorialService::own()['public_name']);
        self::assertSame('', EditorialService::own()['bio']);
        $GLOBALS['wpdb']->profile['status'] = 'active'; self::assertNull(EditorialService::publicById(self::ID));
        self::assertSame(409, EditorialService::decide(self::ID, 2, 'withdraw', '')->get_error_data()['status']);
    }
    public function testRejectionPurgesFieldsAndAuditContainsNoEditorialContent(): void
    {
        $this->submit(); $GLOBALS['profile_admin'] = true;
        self::assertSame('rejected', EditorialService::decide(self::ID, 1, 'reject', 'needs_revision')['state']);
        self::assertSame('', EditorialService::own()['public_name']);
        self::assertSame('', EditorialService::own()['bio']);
        self::assertStringNotContainsString('Atelier', json_encode($GLOBALS['wpdb']->audit));
        self::assertNull(EditorialService::publicById(self::ID));
        self::assertSame('pending', $this->submit(2)['state']);
    }
    public function testFailureRollsBackBothStateAndAudit(): void
    {
        $GLOBALS['wpdb']->failAudit = true;
        self::assertInstanceOf(\WP_Error::class, EditorialService::submit(0, 'Nom', '', '', 0));
        self::assertNull($GLOBALS['wpdb']->editorial);
        $GLOBALS['wpdb']->failAudit = false; $this->submit(); $GLOBALS['profile_admin'] = true;
        $GLOBALS['wpdb']->failCommit = true;
        self::assertInstanceOf(\WP_Error::class, EditorialService::decide(self::ID, 1, 'approve', 'allowed_editorial'));
        self::assertSame('pending', $GLOBALS['wpdb']->editorial['state']); self::assertCount(1, $GLOBALS['wpdb']->audit);
        self::assertFalse($GLOBALS['wpdb']->suppressed);
        $GLOBALS['wpdb']->failCommit = false; $GLOBALS['wpdb']->lock = false;
        self::assertNull(EditorialService::publicById(self::ID));
    }
    public function testValidationLimitsNoExternalPortraitAndUnavailableSchema(): void
    {
        foreach (['', '  ', '<b>Nom</b>', "a\nnom", "\xff", str_repeat('é', 81)] as $name) {
            self::assertSame(400, EditorialService::submit(0, $name, '', '', 0)->get_error_data()['status']);
        }
        foreach (['<img>', "\0", str_repeat('é', 1001)] as $bio) {
            self::assertSame(400, EditorialService::submit(0, 'Nom', $bio, '', 0)->get_error_data()['status']);
        }
        self::assertSame(400, EditorialService::submit('0', 'Nom', '', '', 0)->get_error_data()['status']);
        self::assertSame(400, EditorialService::submit(0, 'Nom', '', 'https://example.test/photo.jpg', 1)->get_error_data()['status']);
        self::assertSame(409, EditorialService::submit(0, 'Nom', '', self::ID, 1)->get_error_data()['status']);
        $GLOBALS['wpdb']->rate = 20;
        self::assertSame(429, EditorialService::submit(0, 'Nom', '', '', 0)->get_error_data()['status']);
        $GLOBALS['profile_options'][EditorialSchema::OPTION] = 'wrong';
        self::assertFalse(EditorialModule::available()); self::assertNull(EditorialService::publicById(self::ID));
    }
}
