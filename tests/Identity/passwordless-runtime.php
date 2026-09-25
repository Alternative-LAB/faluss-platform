<?php
/** Disposable WordPress/MariaDB recipe. Run with wp eval-file, never against a real site. */
global $wpdb;
if (parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1' || !defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Local CLI fixture only'); }
$call = static fn($method, ...$args) => (new ReflectionMethod('Faluss_Identity_Passwordless', $method))->invoke(null, ...$args);
$assert = static function($condition, $message) { if (!$condition) { throw new RuntimeException($message); } };
$mail=''; $stages=[];
add_filter('pre_wp_mail', static function($result,$args) use (&$mail) { $mail=$args['message']; return true; },9,2);
add_action('faluss_identity_passwordless_diagnostic', static function($stage) use (&$stages) { $stages[]=$stage; });
$tables=Faluss_Identity_Schema::get_table_names(); $results=[];
$issue=static function($email) use ($call,&$mail,$assert) {
 wp_set_current_user(0); $_COOKIE=[]; $mail='';
 $assert($call('issue_challenge',$email,'192.0.2.'.random_int(1,250)),'Issue challenge');
 preg_match('/\b[0-9]{6}\b/',$mail,$match); $_POST=['otp'=>$match[0]??'', 'faluss_identity_nonce'=>wp_create_nonce('faluss_identity_verify_code')];
 return $call('read_cookie_state');
};
$challenge=static fn($state) => $wpdb->get_row($wpdb->prepare('SELECT status, attempt_count FROM '.$tables['challenges'].' WHERE challenge_hash=%s',hash('sha256',$state['challenge'])),ARRAY_A);
foreach (['published','incomplete-active','incomplete-pending','new','suspended','registry-failure','new-registry-failure','new-user-hook-failure','privileged'] as $case) {
 $email='fixture-'.bin2hex(random_bytes(8)).'@example.test'; $id=null; $slug=''; $userId=0; $stages=[];
 if (!in_array($case,['new','new-registry-failure','new-user-hook-failure'],true)) {
  $userId=wp_insert_user(['user_login'=>'fixture_'.bin2hex(random_bytes(8)),'user_email'=>$email,'user_pass'=>wp_generate_password(32),'role'=>$case==='privileged'?'administrator':'subscriber']);
  $id=Faluss_Identity_Registry::activate_for_wp_user($userId); $slug='fixture-'.bin2hex(random_bytes(8));
  Faluss_Identity_Public_Profile::reserve_public_slug($id,$slug);
  $wpdb->update($tables['public_profiles'],['display_name'=>'Brouillon conservé','publication_status'=>$case==='published'?'published':'draft'],['faluss_id'=>$id]);
  $wpdb->update($tables['profiles'],['status'=>$case==='incomplete-pending'?'pending':($case==='suspended'?'suspended':'active'),'onboarding_choice'=>'create_card','onboarding_next_step'=>$case==='published'?'complete':'wizard_atomic_wallpaper'],['faluss_id'=>$id]);
 }
 $state=$issue($email);
 $inject=static function($query) use($tables) { return str_starts_with($query,'UPDATE `'.$tables['profiles'].'` SET status =') ? 'SELECT injected_failure FROM missing_fixture_table' : $query; };
 $fault=str_contains($case,'registry-failure');
 if($fault){$wpdb->suppress_errors(true);add_filter('query',$inject);}
 $userHook=static function(){throw new RuntimeException('Injected user_register failure');};
 if($case==='new-user-hook-failure'){add_action('user_register',$userHook);}
 $verified=$call('verify_code_result');
 remove_action('user_register',$userHook);
 if($case==='new-user-hook-failure'){$fault=true;}
 remove_filter('query',$inject);$wpdb->suppress_errors(false);
 $status=$challenge($state)['status'];
 if($fault){
  $assert(!$verified && $status==='pending','Internal failure must retain pending proof');
  if(in_array($case,['new-registry-failure','new-user-hook-failure'],true)){$assert(!get_user_by('email',$email),'Rolled-back WP user must not survive in cache');}
  $verified=$call('verify_code_result');
  $assert($verified instanceof WP_User,'Same OTP retries after rollback');
 }
 $denied=in_array($case,['suspended','privileged'],true);
 $assert(($verified instanceof WP_User)!==$denied,'Expected authentication outcome '.$case);
 $preserved=null; $render='';
 if(!$denied){
  wp_set_current_user($verified->ID);$actual=Faluss_Identity_Registry::get_active_for_wp_user($verified->ID);$profile=Faluss_Identity_Public_Profile::studio_profile($actual);
  $preserved=$id===null||($actual===$id&&$profile['public_slug']===$slug&&$profile['display_name']==='Brouillon conservé');$assert($preserved,'Identity and draft preserved');
  $studio=Faluss_Link::studio_v2_state();$assert(!is_wp_error($studio),'Studio state available');
  $render=\Faluss\Platform\MeStudio\StudioV3::render();
  $assert(str_contains($render,$case==='published'?'data-faluss-studio-v3':'data-faluss-onboarding-v3'),'Correct destination');
  if($case!=='published'&&$id!==null){$assert(str_contains($render,'data-step="v3_wallpaper"'),'Old atomic cursor resumes wallpaper');}
  wp_set_current_user(0); $assert(!$call('verify_code_result'),'Consumed OTP cannot replay');
 }else{
  $assert($challenge($state)['status']==='pending','Denied identity never consumes valid proof');
  if($case==='suspended'){$assert($wpdb->get_var($wpdb->prepare('SELECT status FROM '.$tables['profiles'].' WHERE faluss_id=%s',$id))==='suspended','Suspended status unchanged');}
 }
 $results[]=['case'=>$case,'authenticated'=>!$denied,'internal_failure_challenge'=>$fault?$status:null,'retained'=>$preserved,'stages'=>array_values(array_unique($stages))];
}
foreach(['nonce','cookie','expired','replaced','attempts'] as $case){
 $stages=[];$state=$issue('negative-'.bin2hex(random_bytes(8)).'@example.test');$correct=$_POST['otp'];
 if($case==='nonce'){$_POST['faluss_identity_nonce']='invalid';}
 if($case==='cookie'){$_COOKIE=[];}
 if($case==='expired'){$wpdb->update($tables['challenges'],['expires_at'=>'2000-01-01 00:00:00'],['challenge_hash'=>hash('sha256',$state['challenge'])]);}
 if($case==='replaced'){$call('invalidate_current_challenge');}
 if($case==='attempts'){
  $_POST['otp']=$correct==='000000'?'000001':'000000';
  for($i=0;$i<5;$i++){$assert(!$call('verify_code_result'),'Wrong OTP denied');}
  $_POST['otp']=$correct;
 }
 $assert(!$call('verify_code_result'),'Negative ceremony denied '.$case);
 if($case==='attempts'){$assert((int)$challenge($state)['attempt_count']===5,'Five attempts limit');}
 $results[]=['case'=>$case,'denied'=>true,'stages'=>array_values(array_unique($stages))];
}
wp_set_current_user(0);
$assert(str_contains(\Faluss\Platform\MeStudio\StudioV3::render(),'Se connecter'),'Expired Studio session returns login');
$assert(str_contains(\Faluss\Platform\MeStudio\OnboardingV3::render(),'Se connecter'),'Expired onboarding session returns login');
echo json_encode(['runtime'=>'WordPress/MariaDB local','at'=>gmdate('c'),'cases'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
