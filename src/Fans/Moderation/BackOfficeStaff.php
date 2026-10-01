<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Moderation;

/** Only the explicit messaging moderation capability can be changed here. Never creates an administrator. */
final class BackOfficeStaff
{
    public const CAP = 'moderate_faluss_fans_messages';
    public static function render(): void
    {
        if(!BackOffice::allowed()||!current_user_can('list_users')){BackOfficeView::error('Lecture de l’équipe non autorisée.',403);return;}
        echo '<h1>Équipe et habilitations locales</h1><p>Seuls les administrateurs WordPress existants peuvent recevoir ici la capacité de modération privée. Aucun rôle, compte SSO ou droit global Identity n’est créé.</p>';
        if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
            BackOfficeOperations::guard(['target','expected','grant','confirm','_wpnonce','_wp_http_referer']);
            $result=self::change(ModerationPanel::field('target',$_POST),ModerationPanel::field('expected',$_POST),ModerationPanel::field('grant',$_POST));
            if($result instanceof \WP_Error){BackOfficeView::error('Habilitation non confirmée. Rechargez et vérifiez vos permissions, le compte et le journal.',(int)($result->get_error_data()['status']??503));}
            else{echo '<p role="status">Habilitation locale mise à jour et journalisée.</p>';}
        }
        $raw=ModerationPanel::field('page_number',$_GET);$page=$raw===''?1:(preg_match('/^[1-9][0-9]{0,3}$/D',$raw)===1?(int)$raw:0);
        if($page<1){BackOfficeView::error('Page invalide.',400);return;}
        $query=new \WP_User_Query(['role'=>'administrator','number'=>20,'paged'=>$page,'orderby'=>'ID','order'=>'ASC']);
        echo '<div class="fb-grid">';
        foreach($query->get_results() as $user){
            if(!$user instanceof \WP_User){continue;}$has=user_can($user,self::CAP);
            echo '<article class="fb-card"><h2>'.esc_html($user->user_email).'</h2><p>Administrateur local · modération privée : '.($has?'habilitée':'non habilitée').'.</p>';
            if(current_user_can('promote_users')&&current_user_can('edit_user',$user->ID)){
                echo '<form method="post" action="'.esc_url(BackOffice::url(['view'=>'staff'])).'">';wp_nonce_field('fans_backoffice');
                echo '<input type="hidden" name="target" value="'.(int)$user->ID.'"><input type="hidden" name="expected" value="'.($has?'1':'0').'"><input type="hidden" name="grant" value="'.($has?'0':'1').'"><p><label><input type="checkbox" name="confirm" value="yes" required> Je confirme cette habilitation nominative.</label></p><button class="fb-button" type="submit">'.($has?'Retirer la capacité de modération':'Habiliter à la modération privée').'</button></form>';
            }
            echo '</article>';
        }
        echo '</div><nav class="fb-pagination"><a href="'.esc_url(BackOffice::url(['view'=>'staff'])).'">Revenir au début</a>';
        if($query->get_total()>$page*20){echo '<a href="'.esc_url(BackOffice::url(['view'=>'staff','page_number'=>(string)($page+1)])).'">Page suivante →</a>';}
        echo '</nav>';BackOfficeOperations::journal();
    }

    public static function change(string $target,string $expected,string $grant): bool|\WP_Error
    {
        $error=static fn(string $code,int $status):\WP_Error=>new \WP_Error($code,'Habilitation indisponible.',['status'=>$status]);
        if(!BackOffice::allowed()||!current_user_can('promote_users')||preg_match('/^[1-9][0-9]{0,9}$/D',$target)!==1||!current_user_can('edit_user',(int)$target)){return $error('staff_forbidden',403);}
        if(!in_array($expected,['0','1'],true)||!in_array($grant,['0','1'],true)||$expected===$grant){return $error('staff_invalid',400);}
        if(!AdminActionLog::prepare()){return $error('staff_journal_unavailable',503);}
        global $wpdb;
        $engine=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->usermeta),'ARRAY_A');
        if(!is_array($engine)||strtolower($engine['Engine']??'')!=='innodb'){return $error('staff_storage_unavailable',503);}
        $lock='fans_staff_'.substr(hash('sha256',$wpdb->prefix.$target),0,40);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))!==1){return $error('staff_busy',409);}
        try{
            if($wpdb->query('START TRANSACTION')===false){return $error('staff_storage_unavailable',503);}
            $wpdb->get_results($wpdb->prepare('SELECT umeta_id FROM `'.$wpdb->usermeta.'` WHERE user_id=%d FOR UPDATE',(int)$target));
            if($wpdb->last_error!==''){$wpdb->query('ROLLBACK');return $error('staff_storage_unavailable',503);}
            clean_user_cache((int)$target);$user=get_userdata((int)$target);
            if(!$user instanceof \WP_User||!in_array('administrator',$user->roles,true)||!user_can($user,'manage_options')||(is_multisite()&&is_super_admin($user->ID))){$wpdb->query('ROLLBACK');return $error('staff_target_forbidden',403);}
            if(user_can($user,self::CAP)!==($expected==='1')){$wpdb->query('ROLLBACK');return $error('staff_revision_conflict',409);}
            $event=AdminActionLog::begin($grant==='1'?'staff_grant':'staff_revoke',(int)$target);
            if($event===null){$wpdb->query('ROLLBACK');return $error('staff_journal_unavailable',503);}
            if($grant==='1'){$user->add_cap(self::CAP,true);}else{$user->add_cap(self::CAP,false);}
            if(self::databaseError()){$wpdb->query('ROLLBACK');return $error('staff_write_failed',503);}
            // Verify persisted metadata, not WP_User's optimistic in-memory capability map.
            clean_user_cache((int)$target);
            $stored=get_user_meta((int)$target,$wpdb->get_blog_prefix().'capabilities',true);
            if(!is_array($stored)||!array_key_exists(self::CAP,$stored)||$stored[self::CAP]!==($grant==='1')||!AdminActionLog::finish($event,true)||$wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return $error('staff_write_failed',503);}
            return true;
        }catch(\Throwable){$wpdb->query('ROLLBACK');return $error('staff_write_failed',503);}
        finally{clean_user_cache((int)$target);$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    /** @phpstan-impure Reads the latest database operation. */
    private static function databaseError(): bool { global $wpdb; return $wpdb->last_error!==''; }
}
