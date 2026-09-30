<?php

declare(strict_types=1);

if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'button_recipe') { throw new RuntimeException('Disposable fixture only'); }
global $wpdb;
if (!\Faluss\Platform\Fans\Sso\FansSsoSchema::installOrVerify()) { throw new RuntimeException('SSO fixture schema unavailable'); }
$sessions = [];
foreach (['member-on' => ['subscriber', 'true'], 'member-off' => ['subscriber', 'false'], 'editor' => ['editor', 'true'], 'admin-on' => ['administrator', 'true'], 'admin-off' => ['administrator', 'false']] as $name => [$role, $preference]) {
    $id = wp_insert_user(['user_login' => 'fans_fixture_' . $name, 'user_pass' => wp_generate_password(40), 'user_email' => $name . '@example.invalid', 'role' => $role]);
    if (is_wp_error($id)) { throw new RuntimeException('Unable to seed local account'); }
    update_user_meta($id, 'show_admin_bar_front', $preference);
    if ($role === 'subscriber') {
        $wpdb->insert($wpdb->prefix . 'faluss_fans_identity_links', ['wp_user_id' => $id, 'faluss_id' => wp_generate_uuid4(), 'created_at' => gmdate('Y-m-d H:i:s'), 'last_proved_at' => gmdate('Y-m-d H:i:s')]);
    }
    $sessions[$name] = [LOGGED_IN_COOKIE => wp_generate_auth_cookie($id, time()+3600, 'logged_in'), AUTH_COOKIE => wp_generate_auth_cookie($id, time()+3600, 'auth')];
}
$page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Fans · recette Elementor locale', 'post_name' => 'landing-elementor']);
update_post_meta($page, '_wp_page_template', 'elementor_canvas');
update_post_meta($page, '_elementor_edit_mode', 'builder');
update_post_meta($page, '_elementor_version', ELEMENTOR_VERSION);
$data = [['id'=>'a001', 'elType'=>'section', 'settings'=>['background_background'=>'classic','background_color'=>'#030a07','padding'=>['unit'=>'px','top'=>'100','right'=>'24','bottom'=>'100','left'=>'24','isLinked'=>false]], 'elements'=>[['id'=>'b001','elType'=>'column','settings'=>['_column_size'=>100],'elements'=>[
    ['id'=>'c001','elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>'Faluss Fans','align'=>'center','title_color'=>'#ffffff'],'elements'=>[]],
    ['id'=>'c002','elType'=>'widget','widgetType'=>'text-editor','settings'=>['editor'=>'<p>Recette Elementor locale — aucun compte réel.</p>','align'=>'center','text_color'=>'#ffffff'],'elements'=>[]],
    ['id'=>'c003','elType'=>'widget','widgetType'=>'shortcode','settings'=>['shortcode'=>'[faluss_fans_sso_button return_to="/faluss-fans/fan/espace"]'],'elements'=>[]]
]]]]];
update_post_meta($page, '_elementor_data', wp_slash(wp_json_encode($data)));
update_option('page_on_front', $page);
update_option('show_on_front', 'page');
update_option('permalink_structure', '/%postname%/');
\Faluss\Platform\Fans\Ui\FansUiRoutes::rewrite();
\Faluss\Platform\Fans\Sso\FansSsoService::rewrite();
flush_rewrite_rules(false);
file_put_contents($args[0], wp_json_encode(['sessions'=>$sessions, 'page'=>$page]));
