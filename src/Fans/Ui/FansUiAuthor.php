<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Publications\TextPublicationsModule;
use Faluss\Platform\Fans\Publications\TextPublicationService;
use Faluss\Platform\Fans\Sso\FansSsoService;

/** Native form adapter; the existing REST API remains the authority for every action. */
final class FansUiAuthor
{
    public bool $available = false;
    public bool $active = false;
    public ?\WP_REST_Response $result = null;
    public ?\WP_REST_Response $listing = null;
    /** @var array{publication_id:string,revision:int,body:string,state:string}|null */
    public ?array $item = null;
    public string $key = '';
    public string $text = '';
    public string $error = '';
    public ?string $imageId = null;
    public ?\WP_REST_Response $imageOptions = null;

    public static function load(bool $composeOnly = false): self
    {
        $view = new self();
        if (!TextPublicationsModule::available() || FansSsoService::currentLinkedSubject() === null
            || ($profile = CreatorProfileService::own()) === null) { return $view; }
        $view->available = true;
        $view->active = $profile['status'] === 'active';
        $view->key = wp_generate_uuid4();
        if ($composeOnly) { return $view; }
        $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !isset($_POST['image_action']);
        if ($post) {
            $view->key = self::field('author_action', $_POST) === 'create' ? self::field('creation_key', $_POST) : '';
            $view->text = self::field('text', $_POST);
            $view->result = self::submit();
        }
        $item = self::field('publication', $_GET);
        if ($item !== '') {
            $response = TextPublicationService::validId($item)
                ? self::request('GET', 'text-publications/' . $item . '/private') : new \WP_REST_Response([], 400);
            $view->item = $response->get_status() === 200 ? self::row($response->get_data()) : null;
            if ($view->item === null) { $view->error = 'Ce texte est indisponible ou ne vous appartient pas.'; }
            else {
                $raw = $response->get_data();
                $view->imageId = TextPublicationService::validId($raw['image_id'] ?? null) ? $raw['image_id'] : null;
                $view->imageOptions = self::request('GET', 'images/portraits');
            }
        }
        $cursor = $post ? '' : self::field('cursor', $_GET);
        $view->listing = self::request('GET', 'text-publications/mine', ['per_page' => 6] + ($cursor === '' ? [] : ['cursor' => $cursor]));
        return $view;
    }

    private static function submit(): \WP_REST_Response
    {
        if (wp_verify_nonce(self::field('fans_author_nonce', $_POST), 'fans_author') === false) {
            return new \WP_REST_Response(['code' => 'invalid_nonce'], 403);
        }
        $action = self::field('author_action', $_POST);
        if ($action === 'create') {
            return self::request('POST', 'text-publications', ['text' => self::field('text', $_POST),
                'category' => 'hosted_allowed_content'], self::field('creation_key', $_POST));
        }
        $id = self::field('publication_id', $_POST);
        $revision = self::field('revision', $_POST);
        if (!TextPublicationService::validId($id) || preg_match('/^[1-9][0-9]{0,9}$/D', $revision) !== 1
            || (int) $revision >= 2147483647 || !in_array($action, ['edit', 'withdraw', 'image'], true)
            || ($action === 'withdraw' && self::field('confirm_withdraw', $_POST) !== 'yes')) {
            return new \WP_REST_Response(['code' => 'invalid_author_input'], 400);
        }
        if ($action === 'image') {
            $image = FansUiAuthorImage::input(self::field('image_choice', $_POST), self::field('confirm_image_review', $_POST));
            return $image === null ? new \WP_REST_Response(['code' => 'invalid_image_choice'], 400)
                : self::request('POST', 'text-publications/' . $id . '/image', ['revision' => (int) $revision] + $image);
        }
        return self::request('POST', 'text-publications/' . $id . '/' . $action,
            ['revision' => (int) $revision] + ($action === 'edit' ? ['text' => self::field('text', $_POST)] : []));
    }

    /** @param array<string,mixed> $data */
    private static function request(string $method, string $route, array $data = [], ?string $key = null): \WP_REST_Response
    {
        $request = new \WP_REST_Request($method, '/faluss-fans/v1/' . $route);
        $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        if ($method === 'GET') { $request->set_query_params($data); }
        else {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($data));
            if ($key !== null) { $request->set_header('Idempotency-Key', $key); }
        }
        return rest_do_request($request);
    }

    /** @param array<string,mixed> $source */
    private static function field(string $key, array $source): string
    { return isset($source[$key]) && is_string($source[$key]) ? wp_unslash($source[$key]) : ''; }

    /** @return array{publication_id:string,revision:int,body:string,state:string}|null */
    public static function row(mixed $row): ?array
    {
        if (!is_array($row) || !TextPublicationService::validId($row['publication_id'] ?? null)
            || !is_string($row['body'] ?? null) || strlen($row['body']) > 32000
            || !in_array($row['state'] ?? null, ['pending', 'approved', 'rejected', 'withdrawn'], true)
            || !(is_int($row['revision'] ?? null) || (is_string($row['revision'] ?? null) && preg_match('/^[1-9][0-9]{0,9}$/D', $row['revision']) === 1))
            || (int) $row['revision'] < 1 || (int) $row['revision'] >= 2147483647) { return null; }
        return ['publication_id' => $row['publication_id'], 'revision' => (int) $row['revision'], 'body' => $row['body'], 'state' => $row['state']];
    }

    public function httpStatus(): int
    { return $this->result !== null && $this->result->get_status() >= 400 ? $this->result->get_status() : 200; }
}
