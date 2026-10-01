<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Images\ImagesModule;
use Faluss\Platform\Fans\Images\ImageStorage;
use Faluss\Platform\Fans\Moderation\ModerationPanel;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;

/** Owner-only native upload/list/withdraw adapter; Images remains the authority. */
final class FansUiImages
{
    public bool $available = false;
    public bool $active = false;
    public ?\WP_REST_Response $result = null;
    public ?\WP_REST_Response $listing = null;
    public string $scope = 'live';

    public static function load(): self
    {
        $view = new self();
        if (!ImagesModule::available() || ($profile = CreatorProfileService::own()) === null) { return $view; }
        $view->available = true; $view->active = $profile['status'] === 'active';
        $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['image_action']);
        if ($post) { $view->result = self::submit(); }
        $cursor = $post ? '' : ModerationPanel::field('images_cursor', $_GET);
        $view->scope = $post ? 'live' : (ModerationPanel::field('image_state', $_GET) ?: 'live');
        $view->listing = ModerationPanel::request('GET', 'images', ['scope' => $view->scope] + ($cursor === '' ? [] : ['cursor' => $cursor]));
        $item=ModerationPanel::field('image',$_GET);
        if($item!==''){$row=\Faluss\Platform\Fans\Images\ImageService::ownItem($item);$view->listing=new \WP_REST_Response(['items'=>$row===null?[]:[$row],'next_cursor'=>null],$row===null?404:200);}
        return $view;
    }
    private static function submit(): \WP_REST_Response
    {
        $field = static fn (string $key): string => ModerationPanel::field($key, $_POST);
        if (wp_verify_nonce($field('fans_images_nonce'), 'fans_images') === false) { return new \WP_REST_Response(['code' => 'invalid_nonce'], 403); }
        if (isset($_POST['author_action'])) { return new \WP_REST_Response(['code' => 'ambiguous_form'], 400); }
        if ($field('image_action') === 'upload') {
            /** @var mixed $upload */
            $upload = $_FILES['image'] ?? null;
            if (!is_array($upload) || array_keys($_FILES) !== ['image']) { return new \WP_REST_Response(['code' => 'invalid_image_upload'], 400); }
            if (in_array($upload['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) { return new \WP_REST_Response(['code' => 'image_too_large'], 413); }
            if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || $upload['tmp_name'] === ''
                || !is_int($upload['size'] ?? null) || $upload['size'] < 0) { return new \WP_REST_Response(['code' => 'invalid_image_upload'], 400); }
            $request = new \WP_REST_Request('POST', '/faluss-fans/v1/images');
            $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
            $request->set_file_params(['image' => ['name' => 'image', 'type' => 'application/octet-stream',
                'tmp_name' => $upload['tmp_name'], 'size' => $upload['size'], 'error' => UPLOAD_ERR_OK]]);
            return rest_do_request($request);
        }
        $revision = $field('image_revision'); $id = $field('image_id');
        if ($field('image_action') !== 'withdraw' || $field('confirm_image_withdraw') !== 'yes'
            || !ImageStorage::validId($id) || preg_match('/^[1-9][0-9]{0,9}$/D', $revision) !== 1 || (int) $revision >= 2147483647) {
            return new \WP_REST_Response(['code' => 'invalid_image_decision'], 400);
        }
        return ModerationPanel::request('POST', 'images/' . $id . '/withdraw', ['revision' => (int) $revision]);
    }
    public function httpStatus(): int { return $this->result !== null && $this->result->get_status() >= 400 ? $this->result->get_status() : 200; }
}
