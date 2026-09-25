<?php
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'faluss_fans_me_recipe') { throw new RuntimeException('Local fixture only'); }
global $wpdb;
$tables=Faluss_Identity_Schema::get_table_names();
$secret=trim(file_get_contents('/var/tmp/faluss-fans-http/secret'));
$wpdb->replace($tables['clients'],['client_id'=>'fans-local-recipe','client_name'=>'Fans local','status'=>'active','client_secret_hash'=>Faluss_Identity_Authorization::hash_client_secret($secret),'allowed_scopes'=>'identity.basic identity.email','redirect_uris'=>json_encode(['https://fans.local.test/faluss-fans/sso/callback']),'first_party'=>0,'created_at'=>gmdate('Y-m-d H:i:s')]);
$users=[];
foreach (['success','collision','failure','concurrent','link'] as $name) {
 $login='http_'.$name.'_'.bin2hex(random_bytes(3));
 $id=wp_insert_user(['user_login'=>$login,'user_email'=>$login.'@example.test','user_pass'=>wp_generate_password(32),'role'=>'subscriber']);
 $subject=Faluss_Identity_Registry::activate_for_wp_user($id);
 $users[$name]=['email'=>$login.'@example.test','subject'=>$subject,'cookie'=>wp_generate_auth_cookie($id,time()+3600,'logged_in'),'cookie_name'=>LOGGED_IN_COOKIE];
}
file_put_contents('/var/tmp/faluss-fans-http/fixtures.json',json_encode($users));
echo "Five fictitious active identities and local client registered.\n";
