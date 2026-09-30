<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Moderation;
use Faluss\Platform\Fans\Messaging\MessagePolicy;
use Faluss\Platform\Fans\Messaging\MessageRetention;
use Faluss\Platform\Fans\Messaging\ReportModeration;
use Faluss\Platform\Fans\Ui\FansUiMessages;

final class MessageReportPanel
{
    public static function url(string $item=''): string
    {return add_query_arg(['page'=>ModerationPanel::PAGE,'view'=>'messages']+($item!==''?['item'=>$item]:[]),admin_url('admin.php'));}
    public static function nav(): void
    {if(ReportModeration::allowed()) {echo '<a href="'.esc_url(self::url()).'">Signalements privés</a>';}}
    public static function submit(): \WP_REST_Response
    {
        $field=static fn(string $key):string=>ModerationPanel::field($key,$_POST);
        if(!ReportModeration::allowed()) {return new \WP_REST_Response([],403);}
        if(!MessagePolicy::uuid($field('item_id'))||preg_match('/^[1-9][0-9]{0,8}$/D',$field('revision'))!==1
            ||preg_match('/^(0|[1-9][0-9]?)$/D',$field('days'))!==1||$_FILES!==[]
            ||array_diff(array_keys($_POST),['kind','item_id','revision','action','reason','days','recourse_complete','_wpnonce','_wp_http_referer'])!==[]) {return new \WP_REST_Response([],400);}
        return ModerationPanel::request('POST','message-reports/'.$field('item_id').'/decision',['revision'=>(int)$field('revision'),'action'=>$field('action'),
            'reason'=>$field('reason'),'days'=>(int)$field('days'),'recourse_complete'=>$field('recourse_complete')==='yes']);
    }
    public static function render(?\WP_REST_Response $result): void
    {
        if(!ReportModeration::allowed()) {wp_die('Habilitation de modération privée requise.','',['response'=>403]);}
        $item=ModerationPanel::field('item',$_GET);
        $response=$item===''?ModerationPanel::request('GET','message-reports',['cursor'=>ModerationPanel::field('cursor',$_GET)])
            :(MessagePolicy::uuid($item)?ModerationPanel::request('GET','message-reports/'.$item):new \WP_REST_Response([],400));
        if($response->get_status()>=400) {status_header($response->get_status());}
        $page=$response->get_data();
        ?>
        <div class="wrap faluss-moderation" data-fans-private-proof>
            <header><p class="fm-brand">Faluss <span>by Alternative LAB</span></p><h1>Signalements privés</h1><p>Examen minimal des preuves de messagerie. Accès réservé aux modérateurs expressément habilités.</p></header>
            <nav aria-label="Modération Fans"><a href="<?php echo esc_url(ModerationView::url(false)); ?>">Textes</a><a href="<?php echo esc_url(ModerationView::url(true)); ?>">Images</a><a href="<?php echo esc_url(EditorialModerationView::url()); ?>">Présentations</a><a href="<?php echo esc_url(self::url()); ?>" aria-current="page">Signalements privés</a></nav>
            <?php if($result!==null): ?><p class="fm-notice" role="status"><?php echo $result->get_status()===200?'Décision confirmée et journalisée.':'Décision non confirmée. Relisez l’état et la révision du dossier avant de réessayer.'; ?></p><?php endif; ?>
            <?php self::retention(); ?>
            <?php if($response->get_status()!==200||!is_array($page)): ?><p role="alert">Dossier indisponible ou expiré. Aucune preuve n’est affichée.</p>
            <?php elseif($item!==''): self::item($page);
            else: ?>
                <p class="fm-warning"><?php echo esc_html((string)$page['overdue']); ?> dossier(s) à réexaminer. Aucune clôture ni prolongation de litige n’est automatique.</p>
                <?php if($page['items']===[]): ?><p>Aucun dossier sur cette page.</p><?php endif; ?>
                <div class="fm-items"><?php foreach($page['items'] as $row): ?><article class="fm-card"><p class="fm-meta"><?php echo esc_html(FansUiMessages::state($row['state']==='open'?'open_report':$row['state'])); ?></p><h2>Dossier du <?php echo esc_html($row['created_at']); ?> UTC</h2><p>Motif : <?php echo esc_html($row['reason']); ?> · réexamen avant <?php echo esc_html($row['review_due_at']); ?> UTC.</p><?php if((bool)$row['overdue']): ?><p class="fm-warning">Réexamen en retard.</p><?php endif; ?><a href="<?php echo esc_url(self::url($row['case_id'])); ?>">Examiner le dossier privé →</a></article><?php endforeach; ?></div>
                <?php if($page['next_cursor']!==null): ?><a href="<?php echo esc_url(add_query_arg('cursor',$page['next_cursor'],self::url())); ?>">Page suivante →</a><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
    private static function retention(): void
    {
        $raw=get_option(MessageRetention::STATUS,'');$status=is_string($raw)?json_decode($raw,true):null;
        $ok=is_array($status)&&is_int($status['checked_at']??null)&&$status['checked_at']>time()-7200&&($status['error']??'')===''&&($status['more']??true)===false;
        ?><details class="fm-retention"><summary>Contrôle des purges</summary><p><?php echo $ok?'Dernière exécution terminée sans erreur ni retard de lot signalé.':'Exécution absente, en retard, en erreur ou lots restant à traiter. Faire vérifier le cron de rétention avant toute ouverture.'; ?></p>
        <?php if(is_array($status)&&is_int($status['checked_at']??null)): ?><p>Dernier contrôle : <?php echo esc_html(gmdate('Y-m-d H:i:s',$status['checked_at'])); ?> UTC. Messages ordinaires et preuves suivent des horloges distinctes.</p><?php endif; ?>
        <p>WP-Cron dépend du trafic : une exécution fiable et la gestion des sauvegardes doivent être configurées par l’exploitant. Aucun contenu privé ne figure dans cet état technique.</p></details><?php
    }
    /** @param array<string,mixed> $row */
    private static function item(array $row): void
    {
        ?>
        <article class="fm-card fm-message-proof"><p class="fm-meta"><?php echo esc_html(FansUiMessages::state($row['state']==='open'?'open_report':$row['state'])); ?> · révision <?php echo esc_html((string)$row['revision']); ?></p>
            <h2>Message isolé pour examen</h2><p>Auteur local <?php echo esc_html((string)$row['subject_user']); ?> · envoyé le <?php echo esc_html($row['sent_at']); ?> UTC · motif <?php echo esc_html($row['reason']); ?>.</p><div class="fm-text"><?php echo esc_html($row['body']); ?></div>
            <p>Réexamen avant <?php echo esc_html($row['review_due_at']); ?> UTC. <?php echo $row['final_at']!==null?'Décision définitive : '.esc_html($row['final_at']).' UTC.':'Aucune décision définitive attestée.'; ?></p>
            <?php if($row['hold_until']!==null): ?><p class="fm-warning">Conservation de litige jusqu’au <?php echo esc_html($row['hold_until']); ?> UTC. Réexaminer et motiver tout renouvellement.</p><?php endif; ?>
            <form method="post" action="<?php echo esc_url(self::url($row['case_id'])); ?>" class="fm-decision">
                <?php wp_nonce_field('fans_moderation'); ?><input type="hidden" name="kind" value="message-report"><input type="hidden" name="item_id" value="<?php echo esc_attr($row['case_id']); ?>"><input type="hidden" name="revision" value="<?php echo esc_attr((string)$row['revision']); ?>">
                <label for="fm-message-action">Action après examen</label><select name="action" id="fm-message-action" required><option value="">Choisir une action</option><option value="review">Tracer un réexamen</option>
                    <?php if($row['state']!=='final'): ?><option value="no_action">Décision provisoire · aucune mesure</option><option value="remove">Retirer le texte ordinaire</option><option value="restrict">Restreindre la conversation</option><option value="restore">Restaurer après examen</option><?php endif; ?>
                    <?php if($row['state']==='decided'): ?><option value="finalize">Attester une décision définitive</option><?php endif; ?>
                    <option value="hold">Placer ou renouveler une conservation de litige</option><option value="release_hold">Lever la conservation de litige</option>
                </select>
                <label for="fm-message-reason">Motivation privée obligatoire</label><textarea id="fm-message-reason" name="reason" maxlength="1000" rows="4" required></textarea>
                <label for="fm-message-days">Litige : durée de 1 à 90 jours ; 0 pour toute autre action</label><input id="fm-message-days" name="days" type="number" min="0" max="90" value="0" required>
                <label class="fm-confirm"><input type="checkbox" name="recourse_complete" value="yes"> Pour finaliser : j’atteste que les notifications et les recours applicables ont été traités et que la décision est définitive.</label>
                <p>Une restriction existante n’est levée que par une restauration explicite. Une preuve ne recrée jamais un message ordinaire expiré. La finalisation déclenche les douze mois de conservation de la preuve.</p><button type="submit">Confirmer l’action motivée</button>
            </form>
            <details><summary>Journal des décisions et recours</summary><ul class="fm-journal"><?php foreach($row['events'] as $event): ?><li><?php echo esc_html(implode(' · ',array_map('strval',$event))); ?></li><?php endforeach; ?></ul></details>
            <details><summary>Journal distinct des conservations de litige</summary><ul class="fm-journal"><?php foreach($row['holds'] as $event): ?><li><?php echo esc_html(implode(' · ',array_map('strval',$event))); ?></li><?php endforeach; ?></ul></details>
            <?php if($row['history_truncated']): ?><p>Affichage limité aux 200 dernières traces de chaque journal.</p><?php endif; ?>
        </article>
        <?php
    }
}
