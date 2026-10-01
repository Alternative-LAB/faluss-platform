<?php
declare(strict_types=1);
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'admission_recipe') { throw new RuntimeException('Disposable fixture only'); }
$fixture = json_decode(file_get_contents($args[0]), true);
$id = wp_insert_user(['user_login'=>'recipe_fan','user_pass'=>wp_generate_password(40),'user_email'=>'fan@example.invalid','role'=>'subscriber']);
if (is_wp_error($id)) { throw new RuntimeException('Fixture account failed'); }
global $wpdb;
$wpdb->insert(\Faluss\Platform\Fans\Sso\FansSsoSchema::tables()['links'],['wp_user_id'=>$id,'faluss_id'=>wp_generate_uuid4(),'created_at'=>gmdate('Y-m-d H:i:s'),'last_proved_at'=>gmdate('Y-m-d H:i:s')]);
wp_set_current_user($id);
$cookies = [LOGGED_IN_COOKIE=>wp_generate_auth_cookie($id,time()+3600,'logged_in'),AUTH_COOKIE=>wp_generate_auth_cookie($id,time()+3600,'auth')];
$_COOKIE[LOGGED_IN_COOKIE]=$cookies[LOGGED_IN_COOKIE];
$fixture['sessions']['fan']=['cookies'=>$cookies,'nonce'=>wp_create_nonce('wp_rest'),'id'=>$id];
$fixture['landing']=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Accueil de recette','post_name'=>'landing-recipe','post_content'=>'[faluss_fans_sso_button]']);
file_put_contents($args[0],wp_json_encode($fixture));
