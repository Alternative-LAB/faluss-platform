<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Sso\FansSsoService;

/** Native adapter for the existing structured creator profile request. */
final class FansUiAdmission
{
    public bool $canApply = false;
    public string $category = '';
    public ?\WP_REST_Response $result = null;
    /** @var array{category:string,status:string}|null */
    public ?array $profile = null;

    public static function load(bool $profilesEnabled): self
    {
        $model = new self();
        if (!$profilesEnabled || FansSsoService::currentLinkedSubject() === null) { return $model; }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $model->category = self::field('category');
            if (wp_verify_nonce(self::field('fans_admission_nonce'), 'fans_admission') === false) {
                $model->result = new \WP_REST_Response([], 403);
            } elseif (self::field('admission_action') !== 'apply'
                || !in_array($model->category, CreatorProfileService::CATEGORIES, true)) {
                $model->result = new \WP_REST_Response([], 400);
            } else {
                $model->result = self::request('POST', ['category' => $model->category]);
            }
        }
        $response = self::request('GET');
        $data = $response->get_data();
        if ($response->get_status() === 200 && is_array($data)
            && in_array($data['category'] ?? null, CreatorProfileService::CATEGORIES, true)
            && in_array($data['status'] ?? null, ['pending', 'active', 'suspended'], true)
            && ($data['identity_verified'] ?? null) === false) {
            $model->profile = ['category' => $data['category'], 'status' => $data['status']];
        } else {
            $model->canApply = $response->get_status() === 404 && is_array($data) && ($data['code'] ?? null) === 'profile_not_found';
        }
        return $model;
    }

    /** @param array<string,string>|null $data */
    private static function request(string $method, ?array $data = null): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/faluss-fans/v1/creators/me');
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        if ($data !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($data));
        }
        return rest_do_request($request);
    }

    private static function field(string $key): string
    { return isset($_POST[$key]) && is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : ''; }

    public function httpStatus(): int
    { return $this->result !== null && $this->result->get_status() >= 400 ? $this->result->get_status() : 200; }
}
