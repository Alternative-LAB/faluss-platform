<?php
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'faluss_fans_recipe') { throw new RuntimeException('Local fixture only'); }
global $wpdb;
$fixtures=json_decode(file_get_contents('/var/tmp/faluss-fans-http/fixtures.json'),true);
foreach (['collision','link'] as $name) {
 $id=wp_insert_user(['user_login'=>'existing_'.bin2hex(random_bytes(5)),'user_email'=>$fixtures[$name]['email'],'user_pass'=>wp_generate_password(32),'role'=>'subscriber']);
 if (is_wp_error($id)) { $id=get_user_by('email',$fixtures[$name]['email'])->ID; }
 $fixtures[$name]['local_id']=$id;
 $fixtures[$name]['local_cookie']=wp_generate_auth_cookie($id,time()+3600,'logged_in');
 $fixtures[$name]['local_cookie_name']=LOGGED_IN_COOKIE;
}
file_put_contents('/var/tmp/faluss-fans-http/fixtures.json',json_encode($fixtures));
echo "Local collision and explicit-link accounts ready.\n";
