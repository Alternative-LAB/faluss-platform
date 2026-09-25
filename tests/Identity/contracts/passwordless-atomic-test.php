<?php
// Focused transaction regression with test doubles; the real DB recipe is passwordless-runtime.php.
define('ABSPATH',__DIR__.'/'); define('ARRAY_A','ARRAY_A');
class WP_User { public $ID=17; }
class Faluss_Identity_Schema { public static function get_table_names(){return ['challenges'=>'challenges'];} }
class Faluss_Identity_Registry { public static $available=false; public static function activate_for_wp_user($id){return self::$available?'11111111-1111-4111-8111-111111111111':null;} }
class Faluss_Identity_Front_Preferences { public static function enforce_for_user($user){} }
function wp_salt($name){return 'fixture-salt';}
function wp_check_password($value,$hash){return $value==='123456';}
function is_email($value){return filter_var($value,FILTER_VALIDATE_EMAIL);}
function get_user_by($key,$value){return new WP_User();}
function user_can($user,$cap){return false;}
function clean_user_cache($user){$GLOBALS['cleaned']=true;}
function wp_cache_delete($key,$group){}
function current_time($type,$gmt=false){return gmdate('Y-m-d H:i:s');}
function do_action($hook,...$args){$GLOBALS['diagnostics'][]=$args;}
$wpdb=new class {
 public $last_error=''; public $status='pending'; public $attempts=0; public $queries=[]; private $snapshot;
 public function prepare($query,...$args){return vsprintf(str_replace(['%s','%d'],["'%s'",'%d'],$query),$args);}
 public function get_row($query,$format){return ['id'=>1,'email'=>'fixture@example.test','otp_hash'=>'hash','attempt_count'=>$this->attempts,'status'=>$this->status,'expires_at'=>gmdate('Y-m-d H:i:s',time()+600)];}
 public function query($query){
  $this->queries[]=$query;
  if($query==='START TRANSACTION'){$this->snapshot=$this->status;}
  if($query==='ROLLBACK'){$this->status=$this->snapshot;}
  if(str_contains($query,'SET status =')&&str_contains($query,'consumed_at')){$this->status='consumed';}
  return 1;
 }
};
require dirname(__DIR__,3).'/src/Identity/Legacy/includes/class-faluss-identity-passwordless.php';
$verify=new ReflectionMethod('Faluss_Identity_Passwordless','consume_valid_otp');
$proof=['challenge'=>str_repeat('c',32),'browser_secret'=>str_repeat('b',32)];
$check=static function($value,$message){if(!$value){throw new RuntimeException($message);}};
$check($verify->invoke(null,$proof,'123456')===null,'Registry failure denied');
$check($wpdb->status==='pending'&&!in_array('COMMIT',$wpdb->queries,true),'Registry failure rolls back the unconsumed proof');
$check($GLOBALS['cleaned']===true,'Rolled-back user cache cleared');
$check($GLOBALS['diagnostics']===[['registry_activation']],'Only bounded stage, no email or proof, reaches diagnostic hook');
Faluss_Identity_Registry::$available=true;
$check($verify->invoke(null,$proof,'123456') instanceof WP_User,'Same valid proof retries');
$check($wpdb->status==='consumed','Proof consumed after identity establishment');
$check($verify->invoke(null,$proof,'123456')===null,'Used proof cannot replay');
echo "Passwordless atomic identity and OTP: OK\n";
