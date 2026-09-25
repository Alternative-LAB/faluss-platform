<?php
if (!defined('WP_CLI') || !WP_CLI || DB_NAME !== 'faluss_fans_recipe') { throw new RuntimeException('Local fixture only'); }
$data=json_decode(file_get_contents('/var/tmp/faluss-fans-http/fixtures.json'),true);
$member=get_user_by('email',$data['success']['email']);
$result=[];
foreach (['member'=>$member->ID,'admin'=>1] as $name=>$id) {
 wp_set_current_user($id);
 $cookie=wp_generate_auth_cookie($id,time()+3600,'logged_in');
 $_COOKIE[LOGGED_IN_COOKIE]=$cookie;
 $result[$name]=['cookie'=>$cookie,'cookie_name'=>LOGGED_IN_COOKIE,'nonce'=>wp_create_nonce('wp_rest')];
}
file_put_contents('/var/tmp/faluss-fans-http/rest-fixtures.json',json_encode($result));
echo "REST session fixtures ready.\n";
