<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Moderation;
use Faluss\Platform\Fans\Messaging\MessageOperations;

final class BackOfficeOperations
{
    public static function render(): void
    {
        if(!MessageOperations::allowed()){BackOfficeView::error('Habilitation de modération requise.',403);return;}
        echo '<h1>Exploitation de la messagerie</h1>';
        if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
            self::guard(['operation','confirm','_wpnonce','_wp_http_referer']);
            $action=ModerationPanel::field('operation',$_POST);
            if(!in_array($action,['messages_prepare','messages_purge'],true)){BackOfficeView::error('Opération invalide.',400);return;}
            if(!AdminActionLog::prepare() || ($event=AdminActionLog::begin($action))===null){BackOfficeView::error('Journal indisponible : aucune opération lancée.',503);return;}
            $result=$action==='messages_prepare'?MessageOperations::prepare():MessageOperations::purge();
            $success=!($result instanceof \WP_Error);
            $journaled=AdminActionLog::finish($event,$success);
            if(!$success||!$journaled){BackOfficeView::error('Résultat incomplet ou non confirmé. Relisez le diagnostic et le journal avant de réessayer.',503);}
            else{echo '<p role="status">Opération terminée et journalisée. Les flags et l’attestation de politique restent inchangés.</p>';}
        }
        $status=MessageOperations::status();
        if($status instanceof \WP_Error){BackOfficeView::error('Diagnostic indisponible.',503);return;}
        echo '<table class="fb-table"><thead><tr><th>Contrôle</th><th>Résultat</th></tr></thead><tbody>';
        foreach(['schemas_ready'=>'Schémas vérifiés','sso_ready'=>'Préparation SSO','profiles_ready'=>'Schéma des profils','hourly_event'=>'Événement WP-Cron horaire','retention_healthy'=>'Dernière purge complète et récente','policy_attested'=>'Politique attestée par configuration','admission_open'=>'Nouveaux envois ouverts','private_access'=>'Accès privé de conservation'] as $key=>$label){echo '<tr><th>'.esc_html($label).'</th><td>'.($status[$key]?'Oui':'Non').'</td></tr>';}
        echo '</tbody></table><p>Prochain événement UTC : '.esc_html($status['next_run_utc']??'Aucun').'.</p>';
        if(is_array($status['purge'])){echo '<p>Dernière exécution UTC : '.esc_html(gmdate('Y-m-d H:i:s',(int)($status['purge']['checked_at']??0))).' · lots restant à traiter : '.(!empty($status['purge']['more'])?'oui':'non').'.</p>';}
        echo '<p class="fb-empty">WP-Cron ne garantit pas une exécution sans trafic. L’exploitant doit installer le cron système fiable, traiter les sauvegardes et attester la politique, ses notifications et recours. Cette page n’atteste rien à sa place.</p>';
        self::form('messages_prepare','Préparer les schémas et l’événement horaire');self::form('messages_purge','Exécuter une purge maintenant');
        echo '<p>Pour fermer les nouveaux envois : désactiver FALUSS_PLATFORM_FANS_MESSAGING en configuration serveur. Conserver les schémas, la politique attestée et le cron de rétention ; signalements, recours et purges restent accessibles selon leurs permissions.</p>';
        self::journal();
    }
    /** @param list<string> $keys */
    public static function guard(array $keys): void
    {
        check_admin_referer('fans_backoffice');
        if($_FILES!==[] || array_diff(array_keys($_POST),$keys)!==[] || ModerationPanel::field('confirm',$_POST)!=='yes'){wp_die('Confirmation explicite et paramètres valides requis.','',['response'=>400]);}
    }
    public static function form(string $action,string $label): void
    {
        echo '<form method="post" class="fb-card" action="'.esc_url(BackOffice::url(['view'=>'operations'])).'">';wp_nonce_field('fans_backoffice');
        echo '<input type="hidden" name="operation" value="'.esc_attr($action).'"><p><label><input type="checkbox" name="confirm" value="yes" required> Je confirme cette opération locale.</label></p><button class="fb-button" type="submit">'.esc_html($label).'</button></form>';
    }
    public static function journal(): void
    {
        $rows=AdminActionLog::recent();
        if($rows===null){echo '<p>Aucune lecture du journal opérateur disponible avant sa préparation.</p>';return;}
        echo '<details><summary>50 dernières opérations locales</summary><ul>';
        foreach($rows as $row){echo '<li>'.esc_html(implode(' · ',array_map('strval',$row))).'</li>';}
        echo '</ul><p>started = résultat non encore confirmé ; completed = terminé ; failed = échec. Aucun contenu privé ni secret dans ce journal.</p></details>';
    }
}
