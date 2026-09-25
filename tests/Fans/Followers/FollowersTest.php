<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Followers;

use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Fans\Profiles\CreatorProfileDb;
use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Profiles/CreatorProfileTest.php';

function get_option(string $key, mixed $default = false): mixed
{
    return $GLOBALS['profile_options'][$key] ?? $default;
}
function get_current_user_id(): int { return $GLOBALS['fans_sso_current_user']; }
function add_action(string $hook, callable $callback): void { $GLOBALS['follower_hooks'][$hook] = $callback; }
function register_rest_route(string $namespace, string $route, array $args): void
{
    $GLOBALS['follower_routes'][$namespace . $route] = $args;
}

final class FollowersDb extends CreatorProfileDb
{
    /** @var array<string, bool> */
    public array $follows = [];
    public bool $failWrite = false;

    public function get_var(string $query): mixed
    {
        $last = end($this->prepared);
        $key = ($last['args'][0] ?? '') . ':' . ($last['args'][1] ?? '');
        if (str_contains($query, 'SELECT 1 FROM')) {
            return isset($this->follows[$key]) ? '1' : null;
        }
        if (str_contains($query, 'SELECT COUNT(*)')) {
            $creatorId = (string) ($last['args'][0] ?? '');
            return count(array_filter(array_keys($this->follows), static fn (string $pair): bool => str_ends_with($pair, ':' . $creatorId)));
        }
        return parent::get_var($query);
    }

    public function get_results(string $query, mixed $output = null): array
    {
        if (str_contains($query, 'wp_faluss_fans_follows')) {
            if (str_starts_with($query, 'SHOW FULL COLUMNS')) {
                return [
                    ['Field' => 'follower_user_id', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Extra' => ''],
                    ['Field' => 'creator_id', 'Type' => 'char(36)', 'Null' => 'NO', 'Extra' => ''],
                    ['Field' => 'created_at', 'Type' => 'datetime', 'Null' => 'NO', 'Extra' => ''],
                ];
            }
            if (str_starts_with($query, 'SHOW INDEX')) {
                return [
                    ['Key_name' => 'PRIMARY', 'Non_unique' => '0', 'Seq_in_index' => 1, 'Column_name' => 'follower_user_id'],
                    ['Key_name' => 'PRIMARY', 'Non_unique' => '0', 'Seq_in_index' => 2, 'Column_name' => 'creator_id'],
                    ['Key_name' => 'creator_id', 'Non_unique' => '1', 'Seq_in_index' => 1, 'Column_name' => 'creator_id'],
                ];
            }
        }
        return parent::get_results($query, $output);
    }

    public function query(string $query): int|false
    {
        if (str_contains($query, 'wp_faluss_fans_follows')) {
            if ($this->failWrite) {
                return false;
            }
            $last = end($this->prepared);
            $key = ($last['args'][0] ?? '') . ':' . ($last['args'][1] ?? '');
            if (str_starts_with($query, 'INSERT INTO')) {
                $this->follows[$key] = true;
                return 1;
            }
            if (str_starts_with($query, 'DELETE FROM')) {
                $found = isset($this->follows[$key]);
                unset($this->follows[$key]);
                return $found ? 1 : 0;
            }
        }
        return parent::query($query);
    }
}

final class FollowersTest extends TestCase
{
    protected function setUp(): void
    {
        \Faluss\Platform\Fans\Sso\fans_sso_reset();
        $GLOBALS['wpdb'] = new FollowersDb();
        $GLOBALS['profile_options'] = [
            CreatorProfileSchema::OPTION => CreatorProfileSchema::VERSION,
            FollowersSchema::OPTION => FollowersSchema::VERSION,
        ];
        $GLOBALS['profile_linked'] = true;
        $GLOBALS['profile_admin'] = false;
        $GLOBALS['follower_routes'] = [];
        $GLOBALS['follower_hooks'] = [];
        $GLOBALS['fans_sso_logged_in'] = true;
        $GLOBALS['fans_sso_current_user'] = 17;
        $GLOBALS['fans_sso_users'][17] = new \WP_User(17, ['subscriber']);
        $db = $GLOBALS['wpdb'];
        $db->profile = [
            'creator_id' => '22222222-2222-4222-8222-222222222222',
            'wp_user_id' => 18,
            'category' => 'arts',
            'status' => 'active',
            'created_at' => '2026-09-24 00:00:00',
            'updated_at' => '2026-09-24 00:00:00',
        ];
    }

    public function testModuleAndSchemaStayOnFansAndDependOnProfiles(): void
    {
        $module = new FollowersModule();
        self::assertSame([SiteRole::Fans], $module->roles());
        self::assertSame(['fans-creator-profiles'], $module->dependencies());
        self::assertSame('wp_faluss_fans_follows', FollowersSchema::table());
        self::assertTrue(FollowersSchema::ready());
        $GLOBALS['profile_options'][FollowersSchema::OPTION] = '0';
        self::assertFalse(FollowersSchema::ready());
    }

    public function testFollowAndUnfollowAreIdempotentAndOnlyCountIsPublic(): void
    {
        $id = $GLOBALS['wpdb']->profile['creator_id'];
        self::assertSame(['creator_id' => $id, 'following' => true], FollowersService::follow($id));
        self::assertSame(['creator_id' => $id, 'following' => true], FollowersService::follow($id));
        self::assertCount(1, $GLOBALS['wpdb']->follows);
        self::assertSame(['creator_id' => $id, 'count' => 1], FollowersService::publicCount($id));
        self::assertSame(['creator_id' => $id, 'following' => false], FollowersService::unfollow($id));
        self::assertSame(['creator_id' => $id, 'following' => false], FollowersService::unfollow($id));
        self::assertSame(['creator_id' => $id, 'count' => 0], FollowersService::publicCount($id));
    }

    public function testUnlinkedMemberAndUnpublishedCreatorAreRejectedButUnfollowCanCleanUp(): void
    {
        $id = $GLOBALS['wpdb']->profile['creator_id'];
        $GLOBALS['profile_linked'] = false;
        self::assertInstanceOf(\WP_Error::class, FollowersService::follow($id));
        $GLOBALS['profile_linked'] = true;
        $GLOBALS['wpdb']->profile['status'] = 'suspended';
        self::assertInstanceOf(\WP_Error::class, FollowersService::follow($id));
        self::assertInstanceOf(\WP_Error::class, FollowersService::publicCount($id));
        self::assertSame(['creator_id' => $id, 'following' => false], FollowersService::unfollow($id));
        self::assertInstanceOf(\WP_Error::class, FollowersService::follow('bad'));
    }

    public function testSelfFollowAndStorageFailureAreRejected(): void
    {
        $id = $GLOBALS['wpdb']->profile['creator_id'];
        $GLOBALS['wpdb']->profile['wp_user_id'] = 17;
        self::assertInstanceOf(\WP_Error::class, FollowersService::follow($id));
        $GLOBALS['wpdb']->profile['wp_user_id'] = 18;
        $GLOBALS['wpdb']->failWrite = true;
        self::assertInstanceOf(\WP_Error::class, FollowersService::follow($id));
        self::assertSame([], $GLOBALS['wpdb']->follows);
    }

    public function testApiUsesMemberPermissionForBothWrites(): void
    {
        FollowersRest::routes();
        self::assertCount(2, $GLOBALS['follower_routes']);
        $write = $GLOBALS['follower_routes']['faluss-fans/v1/creators/(?P<creator_id>[0-9a-f-]{36})/follow'];
        self::assertSame(['Faluss\\Platform\\Fans\\Profiles\\CreatorProfileRest', 'memberPermission'], $write[0]['permission_callback']);
        self::assertSame($write[0]['permission_callback'], $write[1]['permission_callback']);
    }
}
