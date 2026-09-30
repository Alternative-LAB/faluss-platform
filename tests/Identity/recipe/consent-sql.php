<?php
// Executed by the existing real WordPress CI recipe, never against an installed site.
if (!defined('WP_CLI') || !WP_CLI || !in_array(DB_NAME, ['sso_recipe', 'button_recipe'], true) || !str_starts_with(ABSPATH, '/var/tmp/')) { throw new RuntimeException('Disposable CI fixture only'); }
global $wpdb;
function consent_expect($condition, $label) { if (!$condition) { throw new RuntimeException($label); } }
consent_expect(Faluss_Identity_Consent::install(), 'Migration ready');
consent_expect(Faluss_Identity_Consent::install(), 'Migration idempotent');
$tables = Faluss_Identity_Schema::get_table_names();
$clientId = 'consent-sql-' . bin2hex(random_bytes(8));
$subject = wp_generate_uuid4();
$uri = 'https://fans.example.test/faluss-fans/sso/callback';
$wpdb->insert($tables['clients'], ['client_id'=>$clientId,'client_name'=>'Consent SQL fixture','status'=>'active','allowed_scopes'=>'identity.basic identity.email','redirect_uris'=>wp_json_encode([$uri]),'first_party'=>0,'created_at'=>gmdate('Y-m-d H:i:s')]);
$find = new ReflectionMethod(Faluss_Identity_Authorization::class, 'find_client');
$issue = new ReflectionMethod(Faluss_Identity_Authorization::class, 'approve_and_issue_code');
$update = new ReflectionMethod(Faluss_Identity_SSO_Clients_Admin::class, 'update_client');
function consent_request($clientId, $find, $uri, $mode) {
    global $wpdb;
    $request = ['client_id'=>$clientId, 'client'=>$find->invoke(null,$clientId), 'request_hash'=>bin2hex(random_bytes(32)), 'redirect_uri'=>$uri,'scopes'=>['identity.basic'],'pkce_challenge'=>str_repeat('p',43),'state'=>'fixture','consent_mode'=>$mode];
    $wpdb->insert(Faluss_Identity_Schema::get_authorization_requests_table(), ['request_hash'=>$request['request_hash'],'client_id'=>$clientId,'redirect_uri'=>$uri,'scopes'=>'identity.basic','pkce_challenge'=>$request['pkce_challenge'],'state'=>'fixture','status'=>'pending','expires_at'=>gmdate('Y-m-d H:i:s',time()+600),'created_at'=>gmdate('Y-m-d H:i:s')]);
    return $request;
}
$request = consent_request($clientId,$find,$uri,'grant');
consent_expect(is_string($issue->invoke(null,$request,$subject)), 'Explicit grant issues code');
consent_expect(Faluss_Identity_Consent::matches($subject,$request), 'Exact consent reused');
consent_expect(!Faluss_Identity_Consent::matches(wp_generate_uuid4(),$request), 'Other identity denied');
$expanded=$request;$expanded['scopes'][]='identity.email';
consent_expect(!Faluss_Identity_Consent::matches($subject,$expanded), 'Unapproved email denied');
// A second real connection revokes after the first transaction acquired a repeatable-read snapshot.
// The locking read must see the committed revocation, not that stale snapshot.
$other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$wpdb->query('START TRANSACTION');
consent_expect(Faluss_Identity_Consent::matches($subject,$request), 'Snapshot sees grant');
$other->query($other->prepare('DELETE FROM `'.Faluss_Identity_Consent::table().'` WHERE faluss_id=%s AND client_id=%s',$subject,$clientId));
consent_expect(Faluss_Identity_Consent::matches($subject,$request), 'Snapshot remains stale');
consent_expect(!Faluss_Identity_Consent::matches($subject,$request,true), 'Locking read sees concurrent revocation');
$wpdb->query('ROLLBACK');
$request=consent_request($clientId,$find,$uri,'grant');
consent_expect(is_string($issue->invoke(null,$request,$subject)), 'Restore explicit grant after concurrency probe');
$stale=consent_request($clientId,$find,$uri,'reuse');
consent_expect(Faluss_Identity_Consent::revoke($subject,$clientId), 'Revoke');
consent_expect(null === $issue->invoke(null,$stale,$subject), 'Revocation between selection and issue is rechecked under lock');
consent_expect('0' === (string)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.$tables['auth_codes'].'` WHERE faluss_id=%s',$subject)), 'Outstanding code revoked');
$request=consent_request($clientId,$find,$uri,'grant');
consent_expect(is_string($issue->invoke(null,$request,$subject)), 'Can explicitly grant again');
$stale=consent_request($clientId,$find,$uri,'reuse');
$sql=$wpdb->prepare('UPDATE `'.$tables['clients'].'` SET client_name=client_name WHERE client_id=%s',$clientId);
consent_expect(false !== $update->invoke(null,$clientId,$sql), 'No-op admin save succeeds');
consent_expect('0' === (string)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.$tables['auth_codes'].'` WHERE faluss_id=%s AND consumed_at IS NULL',$subject)), 'Config change invalidates outstanding codes');
consent_expect(null === $issue->invoke(null,$stale,$subject), 'Config revision race refuses stale reuse');
$fresh=consent_request($clientId,$find,$uri,'reuse');
consent_expect(null === $issue->invoke(null,$fresh,$subject), 'New config cannot use old grant');
$request=consent_request($clientId,$find,$uri,'grant');
consent_expect(is_string($issue->invoke(null,$request,$subject)), 'New config explicit grant');
// A changed permission contract or any client configuration invalidates the exact grant.
foreach (['client_name'=>'Changed','client_secret_hash'=>'changed','redirect_uris'=>[$uri,'https://example.invalid/callback'],'allowed_scopes'=>['identity.basic'],'first_party'=>1,'updated_at'=>'2030-01-01 00:00:00','consent_version'=>'different'] as $key=>$value) {
    $changed=$request;$changed['client'][$key]=$value;
    consent_expect(!Faluss_Identity_Consent::matches($subject,$changed),'Config field invalidates: '.$key);
}
Faluss_Identity_Consent::revoke($subject,$clientId);
$fail = static function ($query) use ($tables) {
    if (str_starts_with($query,'INSERT INTO `'.$tables['auth_codes'].'`')) { return 'INSERT INTO deliberately_missing_consent_table VALUES (1)'; }
    return $query;
};
$wpdb->suppress_errors(true);add_filter('query',$fail);
$request=consent_request($clientId,$find,$uri,'grant');
consent_expect(null === $issue->invoke(null,$request,$subject), 'Code failure');
remove_filter('query',$fail);$wpdb->suppress_errors(false);
consent_expect(!Faluss_Identity_Consent::matches($subject,$request), 'Failed issuance rolls back grant');
// Revision persistence and client update must either both commit or both roll back.
$oldName=$find->invoke(null,$clientId)['client_name'];
$failRevision=static function ($query) {
    if (str_starts_with($query,'INSERT INTO `'.Faluss_Identity_Consent::table().'_clients`')) { return 'INSERT INTO deliberately_missing_consent_table VALUES (1)'; }
    return $query;
};
$wpdb->suppress_errors(true);add_filter('query',$failRevision);
$sql=$wpdb->prepare('UPDATE `'.$tables['clients'].'` SET client_name=%s WHERE client_id=%s','Must roll back',$clientId);
consent_expect(false === $update->invoke(null,$clientId,$sql), 'Failed revision refused');
remove_filter('query',$failRevision);$wpdb->suppress_errors(false);
consent_expect($oldName === $find->invoke(null,$clientId)['client_name'], 'Config rolled back with failed revision');
// Unexpected non-transactional storage must never authorize remembered access.
$grantTable=Faluss_Identity_Consent::table();
$wpdb->query('ALTER TABLE `'.$grantTable.'` ENGINE=MyISAM');
consent_expect(!Faluss_Identity_Consent::ready(), 'Non-InnoDB store unavailable');
consent_expect(false === $update->invoke(null,$clientId,$sql), 'Broken consent store blocks admin config mutation');
$wpdb->query('ALTER TABLE `'.$grantTable.'` ENGINE=InnoDB');
consent_expect(Faluss_Identity_Consent::ready(), 'Transactional store restored');
echo "PASS consent SQL: migration, exact scopes/configuration, owner isolation, revocation/configuration races, rollback and InnoDB refusal\n";
