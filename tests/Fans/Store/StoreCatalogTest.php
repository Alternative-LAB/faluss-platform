<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Store;

use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Fans\Profiles\CreatorProfileDb;
use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__) . '/Profiles/CreatorProfileTest.php';

function get_option(string $key, mixed $default = false): mixed
{
    return $GLOBALS['profile_options'][$key] ?? $default;
}
function update_option(string $key, mixed $value, bool $autoload = false): bool
{
    $GLOBALS['profile_options'][$key] = $value;
    return true;
}
function current_user_can(string $capability): bool
{
    return $capability === 'manage_options' && $GLOBALS['profile_admin'];
}
function add_action(string $hook, callable $callback): void { $GLOBALS['store_hooks'][$hook] = $callback; }
function register_rest_route(string $namespace, string $route, array $args): void
{
    $GLOBALS['store_routes'][$namespace . $route] = $args;
}

final class StoreCatalogDb extends CreatorProfileDb
{
    /** @var array<string,mixed>|null */
    public ?array $product = null;
    public ?string $requestKey = null;
    public bool $failInsert = false;
    public bool $archiveColumn = true;
    public bool $failBackfill = false;
    public bool $failAlter = false;
    public function get_var(string $query): mixed
    {
        if (str_contains($query, 'GET_LOCK') || str_contains($query, 'RELEASE_LOCK')) { return 1; }
        if (str_starts_with($query, 'SHOW TABLES')) { return 'wp_faluss_fans_store_catalog'; }
        return parent::get_var($query);
    }

    public function get_row(string $query, mixed $output = null): ?array
    {
        if (str_contains($query, 'wp_faluss_fans_store_catalog') && !str_starts_with($query, 'SHOW')) {
            $last = end($this->prepared);
            if (str_contains($query, 'WHERE request_key')) {
                return ($last['args'][0] ?? null) === $this->requestKey ? $this->product : null;
            }
            if (str_contains($query, 'WHERE product_id')) {
                return ($last['args'][0] ?? null) === ($this->product['product_id'] ?? null)
                    && (!str_contains($query, 'AND visibility =') || ($this->product['visibility'] ?? null) === 'visible')
                    ? $this->product : null;
            }
        }
        return parent::get_row($query, $output);
    }

    public function get_results(string $query, mixed $output = null): array
    {
        if (str_contains($query, 'wp_faluss_fans_store_catalog')) {
            if (str_starts_with($query, 'SHOW FULL COLUMNS')) {
                return array_map(static fn (array $column): array => [
                    'Field' => $column[0], 'Type' => $column[1], 'Null' => 'NO', 'Extra' => '',
                ], [
                    ['product_id', 'char(36)'], ['request_key', 'char(36)'],
                    ['creator_id', 'char(36)'], ['category', 'varchar(32)'],
                    ['visibility', 'varchar(16)'], ['created_at', 'datetime'],
                    ...($this->archiveColumn ? [['archived', 'tinyint(1)']] : []),
                ]);
            }
            if (str_starts_with($query, 'SHOW INDEX')) {
                return [
                    ['Key_name' => 'PRIMARY', 'Non_unique' => '0', 'Seq_in_index' => 1, 'Column_name' => 'product_id'],
                    ['Key_name' => 'request_key_unique', 'Non_unique' => '0', 'Seq_in_index' => 1, 'Column_name' => 'request_key'],
                    ['Key_name' => 'category_visibility', 'Non_unique' => '1', 'Seq_in_index' => 1, 'Column_name' => 'category'],
                    ['Key_name' => 'category_visibility', 'Non_unique' => '1', 'Seq_in_index' => 2, 'Column_name' => 'visibility'],
                    ['Key_name' => 'creator_id', 'Non_unique' => '1', 'Seq_in_index' => 1, 'Column_name' => 'creator_id'],
                ];
            }
            $last = end($this->prepared);
            if (str_contains($query, 'WHERE (archived = 1')) {
                return $this->product !== null
                    && ($this->product['archived'] === 1 || $this->product['category'] === PurchaseGate::EXTERNAL_ADULT)
                    && $this->product['product_id'] > $last['args'][1] ? [$this->product] : [];
            }
            return $this->product !== null
                && $this->product['archived'] === 0
                && $this->product['visibility'] === 'visible'
                && (!str_contains($query, 'AND category =') || ($last['args'][1] ?? null) === $this->product['category'])
                ? [$this->product]
                : [];
        }
        return parent::get_results($query, $output);
    }

    public function query(string $query): int|false
    {
        if (str_starts_with($query, 'ALTER TABLE')) {
            if ($this->failAlter) { return false; }
            $this->archiveColumn = true;
            if ($this->product !== null) { $this->product['archived'] = 0; }
            return 1;
        }
        if (str_starts_with($query, 'UPDATE `wp_faluss_fans_store_catalog`')) {
            if ($this->failBackfill) { return false; }
            if ($this->product !== null && $this->product['category'] === PurchaseGate::EXTERNAL_ADULT) {
                $this->product['archived'] = 1;
            }
            return 1;
        }
        if (str_starts_with($query, 'INSERT INTO') && str_contains($query, 'wp_faluss_fans_store_catalog')) {
            if ($this->failInsert || $this->product !== null) {
                return false;
            }
            $last = end($this->prepared);
            [$productId, $requestKey, $creatorId, $category, $visibility, $created] = $last['args'];
            $this->requestKey = $requestKey;
            $this->product = [
                'product_id' => $productId, 'creator_id' => $creatorId,
                'category' => $category, 'visibility' => $visibility,
                'created_at' => $created, 'archived' => 0,
            ];
            return 1;
        }
        return parent::query($query);
    }
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StoreCatalogTest extends TestCase
{
    private const CREATOR = '22222222-2222-4222-8222-222222222222';
    private const REQUEST = '33333333-3333-4333-8333-333333333333';

    protected function setUp(): void
    {
        \Faluss\Platform\Fans\Sso\fans_sso_reset();
        $GLOBALS['wpdb'] = new StoreCatalogDb();
        $GLOBALS['profile_options'] = [
            CreatorProfileSchema::OPTION => CreatorProfileSchema::VERSION,
            StoreCatalogSchema::OPTION => StoreCatalogSchema::VERSION,
        ];
        $GLOBALS['profile_admin'] = false;
        $GLOBALS['profile_linked'] = true;
        $GLOBALS['store_routes'] = [];
        $GLOBALS['store_hooks'] = [];
        $GLOBALS['wpdb']->profile = [
            'creator_id' => self::CREATOR, 'wp_user_id' => 17,
            'category' => 'arts', 'status' => 'active',
            'created_at' => '2026-09-24 00:00:00', 'updated_at' => '2026-09-24 00:00:00',
        ];
    }

    public function testOnlyAllowedCategoryIsPublicAndNotPurchasable(): void
    {
        $module = new StoreCatalogModule();
        self::assertSame([SiteRole::Fans], $module->roles());
        self::assertSame(['fans-creator-profiles'], $module->dependencies());
        self::assertTrue(StoreCatalogSchema::ready());
        $response = StoreCatalogRest::categories();
        self::assertSame([PurchaseGate::HOSTED], array_column($response->data, 'category'));
        self::assertSame([false], array_column($response->data, 'purchasable'));
        self::assertSame('Droit à une livraison adulte externe', PurchaseGate::categoryLabel(PurchaseGate::EXTERNAL_ADULT));
    }

    private function legacy(): array
    {
        return $GLOBALS['wpdb']->product = [
            'product_id' => '11111111-1111-4111-8111-111111111111',
            'creator_id' => self::CREATOR, 'category' => PurchaseGate::EXTERNAL_ADULT,
            'visibility' => 'visible', 'created_at' => '2026-09-24 00:00:00', 'archived' => 1,
        ];
    }

    public function testAllowedCreationRemainsIdempotentAndAdultCreationIsForbidden(): void
    {
        self::assertSame(403, StoreCatalogService::create(self::CREATOR, PurchaseGate::HOSTED, self::REQUEST)->get_error_data()['status']);
        $GLOBALS['profile_admin'] = true;
        self::assertSame('category_archived', StoreCatalogService::create(self::CREATOR, PurchaseGate::EXTERNAL_ADULT, self::REQUEST)->get_error_code());
        self::assertNull($GLOBALS['wpdb']->product);
        $created = StoreCatalogService::create(self::CREATOR, PurchaseGate::HOSTED, self::REQUEST);
        self::assertIsArray($created);
        self::assertSame($created, StoreCatalogService::create(self::CREATOR, PurchaseGate::HOSTED, self::REQUEST));
        self::assertArrayNotHasKey('request_key', $created);
        self::assertArrayNotHasKey('content', $created);
        self::assertSame([$created], StoreCatalogService::publicList(null));
        self::assertSame($created, StoreCatalogService::publicById($created['product_id']));
        self::assertSame(503, StoreCatalogService::purchase($created['product_id'])->get_error_data()['status']);
        $GLOBALS['wpdb']->product['category'] = PurchaseGate::EXTERNAL_ADULT;
        self::assertSame(403, StoreCatalogService::purchase($created['product_id'])->get_error_data()['status']);
        self::assertNull(StoreCatalogService::publicById($created['product_id']));
    }

    public function testLegacyReferencesStayArchivedAfterForgedCategoryOrVisibilityChange(): void
    {
        $old = $this->legacy();
        foreach ([PurchaseGate::EXTERNAL_ADULT, PurchaseGate::HOSTED] as $category) {
            $GLOBALS['wpdb']->product['category'] = $category;
            foreach (['hidden', 'visible'] as $visibility) {
                $GLOBALS['wpdb']->product['visibility'] = $visibility;
                self::assertNull(StoreCatalogService::publicById($old['product_id']));
                self::assertSame([], StoreCatalogService::publicList(null));
                $request = new \WP_REST_Request(['product_id' => $old['product_id'], 'category' => PurchaseGate::HOSTED]);
                self::assertSame(403, StoreCatalogRest::purchase($request)->get_error_data()['status']);
                self::assertSame(404, StoreCatalogRest::product($request)->get_error_data()['status']);
            }
        }
        $GLOBALS['profile_admin'] = true;
        $GLOBALS['wpdb']->requestKey = self::REQUEST;
        self::assertSame(403, StoreCatalogService::create(self::CREATOR, PurchaseGate::HOSTED, self::REQUEST)->get_error_data()['status']);
        self::assertSame(400, StoreCatalogService::publicList(PurchaseGate::EXTERNAL_ADULT)->get_error_data()['status']);
        self::assertSame(400, StoreCatalogService::publicList(['forged'])->get_error_data()['status']);
        self::assertSame(404, StoreCatalogService::purchase('44444444-4444-4444-8444-444444444444')->get_error_data()['status']);
    }

    public function testPrivateArchiveRequiresAdministratorAndNonceAndSurvivesSuspension(): void
    {
        $old = $this->legacy();
        self::assertSame(403, StoreCatalogService::archivedById($old['product_id'])->get_error_data()['status']);
        self::assertSame(403, StoreCatalogService::archiveList(null)->get_error_data()['status']);
        StoreCatalogRest::routes();
        $route = $GLOBALS['store_routes']['faluss-fans/v1/store/admin/archive'];
        $permission = $route['permission_callback'];
        self::assertFalse($permission(new \WP_REST_Request([], ['X-WP-Nonce' => 'valid-nonce'])));
        $GLOBALS['profile_admin'] = true;
        self::assertFalse($permission(new \WP_REST_Request()));
        self::assertTrue($permission(new \WP_REST_Request([], ['X-WP-Nonce' => 'valid-nonce'])));
        $GLOBALS['wpdb']->profile['status'] = 'suspended';
        $response = StoreCatalogRest::archivedProduct(new \WP_REST_Request(['product_id' => $old['product_id']]));
        self::assertSame($old['product_id'], $response->data['product_id']);
        self::assertTrue($response->data['archived']);
        self::assertSame('private, no-store, max-age=0', $response->headers['Cache-Control']);
        self::assertSame('Cookie, X-WP-Nonce', $response->headers['Vary']);
        self::assertCount(1, StoreCatalogService::archiveList(null)['items']);
        self::assertSame([], StoreCatalogService::archiveList($old['product_id'])['items']);
        self::assertSame(400, StoreCatalogService::archiveList('forged')->get_error_data()['status']);
    }

    public function testAdditiveMigrationPreservesOriginalFieldsAndResumesAfterFailure(): void
    {
        $old = $this->legacy();
        unset($old['archived']);
        $db = $GLOBALS['wpdb'];
        $db->product = $old;
        $db->archiveColumn = false;
        $GLOBALS['profile_options'][StoreCatalogSchema::OPTION] = '1';
        self::assertFalse(StoreCatalogSchema::ready());
        $db->failAlter = true;
        self::assertFalse(StoreCatalogSchema::installOrVerify());
        self::assertSame($old, $db->product);
        $db->failAlter = false;
        $db->failBackfill = true;
        self::assertFalse(StoreCatalogSchema::installOrVerify());
        self::assertSame('1', get_option(StoreCatalogSchema::OPTION));
        self::assertFalse(StoreCatalogSchema::ready());
        $db->failBackfill = false;
        self::assertTrue(StoreCatalogSchema::installOrVerify());
        self::assertSame($old + ['archived' => 1], $db->product);
        self::assertTrue(StoreCatalogSchema::ready());
        self::assertTrue(StoreCatalogSchema::installOrVerify());
        self::assertSame($old + ['archived' => 1], $db->product);
    }
}
