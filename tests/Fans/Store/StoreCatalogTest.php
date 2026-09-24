<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Store;

use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Fans\Profiles\CreatorProfileDb;
use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

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
            return $this->product !== null
                && $this->product['visibility'] === 'visible'
                && (!str_contains($query, 'AND category =') || ($last['args'][1] ?? null) === $this->product['category'])
                ? [$this->product]
                : [];
        }
        return parent::get_results($query, $output);
    }

    public function query(string $query): int|false
    {
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
                'created_at' => $created,
            ];
            return 1;
        }
        return parent::query($query);
    }
}

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

    public function testCategoriesAreDistinctVisibleAndNotPurchasable(): void
    {
        $module = new StoreCatalogModule();
        self::assertSame([SiteRole::Fans], $module->roles());
        self::assertSame(['fans-creator-profiles'], $module->dependencies());
        self::assertTrue(StoreCatalogSchema::ready());
        $response = StoreCatalogRest::categories();
        self::assertSame([PurchaseGate::HOSTED, PurchaseGate::EXTERNAL_ADULT], array_column($response->data, 'category'));
        self::assertSame([false, false], array_column($response->data, 'purchasable'));
        self::assertSame('Droit à une livraison adulte externe', $response->data[1]['label']);
    }

    public function testAdminCreatesOnlyStructuredListingWithIdempotencyKey(): void
    {
        self::assertInstanceOf(\WP_Error::class, StoreCatalogService::create(self::CREATOR, PurchaseGate::EXTERNAL_ADULT, self::REQUEST));
        $GLOBALS['profile_admin'] = true;
        $created = StoreCatalogService::create(self::CREATOR, PurchaseGate::EXTERNAL_ADULT, self::REQUEST);
        self::assertIsArray($created);
        self::assertSame(PurchaseGate::EXTERNAL_ADULT, $created['category']);
        self::assertSame('hidden', $created['visibility']);
        self::assertSame($created, StoreCatalogService::create(self::CREATOR, PurchaseGate::EXTERNAL_ADULT, self::REQUEST));
        self::assertInstanceOf(\WP_Error::class, StoreCatalogService::create(self::CREATOR, PurchaseGate::HOSTED, self::REQUEST));
        self::assertInstanceOf(\WP_Error::class, StoreCatalogService::create(self::CREATOR, 'other', '44444444-4444-4444-8444-444444444444'));
        self::assertArrayNotHasKey('content', $GLOBALS['wpdb']->product);
        self::assertArrayNotHasKey('media', $GLOBALS['wpdb']->product);
        self::assertArrayNotHasKey('delivery_url', $GLOBALS['wpdb']->product);
        self::assertArrayNotHasKey('request_key', $created);
        self::assertSame([], StoreCatalogService::publicList(PurchaseGate::EXTERNAL_ADULT));
        self::assertNull(StoreCatalogService::publicById($created['product_id']));
        self::assertSame(404, StoreCatalogRest::product(new \WP_REST_Request(['product_id' => $created['product_id']]))->data['status']);
        self::assertSame([], StoreCatalogService::publicList(PurchaseGate::HOSTED));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAdultPurchaseIsDeniedAtServiceAndApiEvenIfAFlagIsPresent(): void
    {
        $GLOBALS['profile_admin'] = true;
        $created = StoreCatalogService::create(self::CREATOR, PurchaseGate::EXTERNAL_ADULT, self::REQUEST);
        self::assertIsArray($created);
        if (!defined('FALUSS_FANS_ADULT_PROVIDER_ACCEPTED')) {
            define('FALUSS_FANS_ADULT_PROVIDER_ACCEPTED', true);
        }
        $service = StoreCatalogService::purchase($created['product_id']);
        self::assertSame('external_adult_purchase_blocked', $service->get_error_code());
        self::assertSame(403, $service->data['status']);
        $request = new \WP_REST_Request(['product_id' => $created['product_id']]);
        $api = StoreCatalogRest::purchase($request);
        self::assertSame('external_adult_purchase_blocked', $api->get_error_code());
        self::assertSame(403, $api->data['status']);
        $GLOBALS['wpdb']->product['category'] = PurchaseGate::HOSTED;
        self::assertSame(503, StoreCatalogRest::purchase($request)->data['status']);
        $GLOBALS['wpdb']->product['category'] = PurchaseGate::EXTERNAL_ADULT;
        self::assertSame(403, StoreCatalogRest::purchase($request)->data['status']);
        StoreCatalogRest::routes();
        $route = $GLOBALS['store_routes']['faluss-fans/v1/store/products/(?P<product_id>[0-9a-f-]{36})/purchase'];
        self::assertSame([StoreCatalogRest::class, 'purchase'], $route['callback']);
    }

    public function testHostedPurchaseAlsoRemainsClosedAndUnknownProductCreatesNoOrder(): void
    {
        $GLOBALS['profile_admin'] = true;
        $created = StoreCatalogService::create(self::CREATOR, PurchaseGate::HOSTED, self::REQUEST);
        self::assertIsArray($created);
        self::assertSame('visible', $created['visibility']);
        self::assertSame('hosted_purchase_not_open', StoreCatalogService::purchase($created['product_id'])->get_error_code());
        self::assertSame(503, StoreCatalogRest::purchase(new \WP_REST_Request(['product_id' => $created['product_id']]))->data['status']);
        $GLOBALS['wpdb']->product['category'] = PurchaseGate::EXTERNAL_ADULT;
        self::assertSame('external_adult_purchase_blocked', StoreCatalogService::purchase($created['product_id'])->get_error_code());
        self::assertNull(StoreCatalogService::publicById($created['product_id']));
        self::assertSame([], StoreCatalogService::publicList(PurchaseGate::EXTERNAL_ADULT));
        self::assertSame(403, StoreCatalogRest::purchase(new \WP_REST_Request(['product_id' => $created['product_id']]))->data['status']);
        self::assertSame('product_not_found', StoreCatalogService::purchase('44444444-4444-4444-8444-444444444444')->get_error_code());
        self::assertSame('invalid_category', PurchaseGate::refusePurchase('unknown')->get_error_code());
        self::assertNull(StoreCatalogService::publicById('bad'));
    }

    public function testSuspendedCreatorHidesListingButAdultPurchaseStillReturns403(): void
    {
        $GLOBALS['profile_admin'] = true;
        $created = StoreCatalogService::create(self::CREATOR, PurchaseGate::EXTERNAL_ADULT, self::REQUEST);
        self::assertIsArray($created);
        $GLOBALS['wpdb']->profile['status'] = 'suspended';
        self::assertNull(StoreCatalogService::publicById($created['product_id']));
        self::assertSame([], StoreCatalogService::publicList(PurchaseGate::EXTERNAL_ADULT));
        self::assertSame('external_adult_purchase_blocked', StoreCatalogService::purchase($created['product_id'])->get_error_code());
        self::assertSame(403, StoreCatalogRest::purchase(new \WP_REST_Request(['product_id' => $created['product_id']]))->data['status']);
    }
}
