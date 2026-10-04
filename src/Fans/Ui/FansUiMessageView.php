<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Ui;

/** Text-only private reading; public creator presentations never imply a Fan identity. */
final class FansUiMessageView
{
    public static function render(FansUiMessages $v): void
    {
        if(!$v->available) {echo '<section class="fu-panel fu-message-empty"><h2>Messagerie indisponible</h2><p>Le service n’est pas ouvert sur cette installation. Aucun message ne peut être envoyé.</p></section>';return;}
        ?>
        <section class="fu-messages" data-fans-private-reading aria-label="Messagerie privée">
        <?php if(!\Faluss\Platform\Fans\Messaging\MessageModule::available()): ?><p class="fu-message-notice">Les nouveaux envois sont fermés. Vos conversations conservées, blocages, signalements et recours restent accessibles.</p><?php endif; ?>
        <?php if($v->result!==null): ?><p class="fu-message-notice" role="<?php echo $v->result->get_status()>=400?'alert':'status'; ?>"><?php echo esc_html($v->notice()); ?></p><?php endif; ?>
        <div class="fu-message-layout<?php echo $v->threadId!==''||$v->creatorId!==''||$v->section!=='inbox'?' has-selection':''; ?>">
            <aside class="fu-message-list" aria-label="Mes conversations">
                <header class="fu-message-heading"><h2>Messages</h2><a href="<?php echo esc_url($v->url()); ?>" aria-label="Actualiser la liste des conversations">Actualiser</a></header>
                <div class="fu-message-list-scroll" tabindex="0" aria-label="Liste des conversations">
                    <?php if($v->section==='inbox'): self::listing($v); else: ?><a class="fu-message-preview" href="<?php echo esc_url($v->url()); ?>">← Toutes les conversations</a><?php endif; ?>
                </div>
                <footer class="fu-message-list-footer">
                    <a class="fu-link" href="<?php echo esc_url(FansUiRoutes::url($v->role,'explorer')); ?>">Trouver un créateur ↗</a>
                    <details class="fu-message-tools"><summary>Confidentialité et aide</summary>
                        <nav class="fu-message-tabs" aria-label="Mes échanges">
                        <?php foreach(['inbox'=>'Conversations','blocks'=>'Mes blocages','reports'=>'Signalements et recours'] as $key=>$label): ?>
                            <a href="<?php echo esc_url($v->url(['section'=>$key])); ?>" <?php echo $v->section===$key?'aria-current="page"':''; ?>><?php echo esc_html($label); ?></a>
                        <?php endforeach; ?>
                        </nav>
                        <details class="fu-message-policy"><summary>Demandes et conservation</summary>
                            <p>Une demande : 1 000 caractères maximum. Une conversation acceptée : messages de 2 000 caractères maximum. Aucun média ni pièce jointe. Vous pouvez bloquer et signaler un message reçu.</p>
                            <p>L’ouverture directe pour les abonnés Faluss Max ou au Créateur est indisponible ici : leur abonnement ne peut pas encore être vérifié. La demande suit donc le parcours d’acceptation.</p>
                            <p>Les messages ordinaires expirent douze mois après le dernier envoi. Lire ou bloquer ne prolonge pas cette durée. Un signalement conserve séparément le message choisi et les éléments nécessaires au dossier, réservés aux modérateurs habilités.</p>
                            <p>Ces preuves sont supprimées douze mois après la décision définitive, recours compris, sauf conservation de litige motivée et réexaminée. Les décisions et les recours sont accessibles dans « Signalements et recours ».</p>
                        </details>
                    </details>
                </footer>
            </aside>
            <div class="fu-message-chat" id="fu-conversation">
                <a class="fu-message-return" href="<?php echo esc_url($v->url()); ?>">← Conversations</a>
                <?php if($v->section!=='inbox'): ?>
                    <header class="fu-message-heading"><h2><?php echo $v->section==='blocks'?'Mes blocages':'Signalements et recours'; ?></h2></header>
                    <div class="fu-message-scroll" tabindex="0" aria-label="Mes dossiers privés"><?php self::privateList($v); ?></div>
                <?php elseif($v->conversation!==null): self::conversation($v);
                elseif($v->creatorId!==''): self::request($v);
                else: ?><div class="fu-message-empty"><span aria-hidden="true">✉</span><h2>Vos conversations, ici</h2><p>Sélectionnez un échange pour le lire.</p></div><?php endif; ?>
            </div>
        </div>
        </section>
        <?php
    }
    private static function listing(FansUiMessages $v): void
    {
        $page=$v->listing?->get_data();
        if($v->listing?->get_status()!==200||!is_array($page)) {echo '<p role="alert">Liste indisponible. Réessayez plus tard.</p>';return;}
        if($page['items']===[]) {echo '<p class="fu-message-list-empty">Aucune conversation pour le moment.</p>';}
        foreach($page['items'] as $row):
            $person=$v->correspondent($row['as_creator'],$row['creator_id']); ?>
            <a class="fu-message-preview" href="<?php echo esc_url($v->url(['thread'=>$row['thread_id']])); ?>" <?php echo $row['thread_id']===$v->threadId?'aria-current="true"':''; ?>>
                <?php self::avatar($person); ?><span class="fu-message-preview-text"><strong><?php echo esc_html($person['name']); ?></strong><small><?php echo esc_html(FansUiMessages::state($row['state'])); ?></small><?php self::date($row['last_sent_at']); ?></span>
            </a>
        <?php endforeach;
        self::next($v,$page['next_cursor']);
    }
    /** @param array{name:string,portrait:string} $person */
    private static function avatar(array $person): void
    {
        ?><span class="fu-message-avatar" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/></svg><?php if($person['portrait']!==''): ?><img src="<?php echo esc_url($person['portrait']); ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"><?php endif; ?></span><?php
    }
    private static function date(string $value): void
    {
        // The service returns UTC; retain the full instant for assistive technology.
        ?><time datetime="<?php echo esc_attr(str_replace(' ','T',$value).'Z'); ?>" title="<?php echo esc_attr($value.' UTC'); ?>" aria-label="<?php echo esc_attr($value.' UTC'); ?>"><?php echo esc_html(substr($value,8,2).'/'.substr($value,5,2).' · '.substr($value,11,5).' UTC'); ?></time><?php
    }
    private static function request(FansUiMessages $v): void
    {
        if(!$v->recipientAvailable()) {echo '<div class="fu-message-empty"><h2>Destinataire indisponible</h2><p>Ce profil ne peut pas recevoir votre demande.</p></div>';return;}
        $person=$v->correspondent(false,$v->creatorId);
        ?>
        <header class="fu-message-heading"><?php self::avatar($person); ?><div><h2><?php echo esc_html($person['name']); ?></h2><p>Nouvelle demande</p></div></header>
        <div class="fu-message-scroll fu-message-empty"><h3>Faites le premier pas</h3><p>Présentez-vous en quelques mots. Le créateur pourra accepter ou refuser votre demande.</p></div>
        <form class="fu-message-form fu-message-composer" method="post" action="<?php echo esc_url($v->url(['creator'=>$v->creatorId])); ?>">
            <?php self::fields('request',['creator_id'=>$v->creatorId,'key'=>$v->key]); ?>
            <label for="fu-message-body">Votre demande textuelle</label>
            <div class="fu-message-input"><textarea id="fu-message-body" name="body" maxlength="1000" required rows="2" aria-describedby="fu-message-request-limit" placeholder="Présentez votre demande…"><?php echo esc_textarea($v->draft); ?></textarea><button type="submit">Envoyer ma demande</button></div>
            <p id="fu-message-request-limit">1 000 caractères · texte uniquement · 2 demandes par heure maximum.</p>
        </form>
        <?php
    }
    private static function conversation(FansUiMessages $v): void
    {
        $row=$v->conversation?->get_data();
        if($v->conversation?->get_status()!==200||!is_array($row)) {echo '<div class="fu-message-empty"><h2>Conversation indisponible</h2><p>Elle a pu expirer ou vous n’y avez plus accès.</p></div>';return;}
        $person=$v->correspondent($row['as_creator'],$row['creator_id']);
        ?>
        <header class="fu-message-heading"><?php self::avatar($person); ?><div><h2><?php echo esc_html($person['name']); ?></h2><p><?php echo esc_html(FansUiMessages::state($row['state'])); ?></p></div>
            <details class="fu-message-options"><summary aria-label="Options de la conversation">•••</summary><div>
                <a href="<?php echo esc_url($v->url(['thread'=>$row['thread_id']])); ?>">Actualiser l’échange</a>
                <?php self::decision($v,$row,$row['blocked_by_me']?'unblock':'block',$row['blocked_by_me']?'Débloquer mon côté':'Bloquer cet échange'); ?>
                <a href="<?php echo esc_url($v->url(['section'=>'reports'])); ?>">Signalements et recours</a>
                <a href="<?php echo esc_url($v->url(['section'=>'blocks'])); ?>">Mes blocages</a>
            </div></details>
        </header>
        <?php if($row['as_creator']&&$row['state']==='pending'&&!$row['blocked']): ?><div class="fu-message-actions"><span>Ouvrir cet échange ?</span><?php self::decision($v,$row,'accept','Accepter la demande');self::decision($v,$row,'refuse','Refuser'); ?></div><?php endif; ?>
        <div class="fu-message-scroll" tabindex="0" aria-label="Historique des messages">
        <?php if(isset($_GET['after'])&&$_GET['after']!=='0'): ?><a class="fu-link" href="<?php echo esc_url($v->url(['thread'=>$row['thread_id']])); ?>">← Début de l’échange</a><?php endif; ?>
        <ol class="fu-message-log" aria-label="Messages conservés">
        <?php foreach($row['messages'] as $message): ?>
            <li class="fu-message-bubble<?php echo $message['mine']?' is-mine':''; ?>"><p class="fu-message-sender"><?php echo $message['mine']?'Vous':esc_html($person['name']); ?></p>
                <p class="fu-message-body"><?php echo esc_html($message['body']!==''?$message['body']:'Message retiré par la modération.'); ?></p><?php self::date($message['sent_at']); ?>
                <?php if(!$message['mine']&&$message['body']!==''): ?><details><summary>Signaler ce message</summary><form method="post" action="<?php echo esc_url($v->url(['thread'=>$row['thread_id']])); ?>">
                    <?php self::fields('report',['thread_id'=>$row['thread_id'],'message_id'=>$message['message_id']]); ?>
                    <label for="report-<?php echo esc_attr($message['message_id']); ?>">Motif du signalement</label><select name="reason" id="report-<?php echo esc_attr($message['message_id']); ?>" required><option value="">Choisir un motif</option><option value="harassment">Harcèlement</option><option value="spam">Spam</option><option value="prohibited_content">Contenu interdit</option><option value="other">Autre motif</option></select>
                    <p>Seul ce message et son contexte minimal seront isolés pour la modération.</p><button type="submit">Confirmer le signalement</button>
                </form></details><?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ol>
        <?php if($row['next_after']!==null): ?><a class="fu-link" href="<?php echo esc_url($v->url(['thread'=>$row['thread_id'],'after'=>$row['next_after']])); ?>">Messages suivants →</a><?php endif; ?>
        </div>
        <?php if($row['can_send']): ?>
            <form class="fu-message-form fu-message-composer" method="post" action="<?php echo esc_url($v->url(['thread'=>$row['thread_id']])); ?>">
                <?php self::fields('send',['thread_id'=>$row['thread_id'],'key'=>$v->key]); ?>
                <label for="fu-message-body">Votre message</label><div class="fu-message-input"><textarea name="body" id="fu-message-body" rows="2" maxlength="2000" required aria-describedby="fu-message-send-limit" placeholder="Écrire un message…"><?php echo esc_textarea($v->draft); ?></textarea><button type="submit" aria-label="Envoyer le message"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m3 11 18-8-8 18-2-8-8-2Zm8 2L21 3"/></svg></button></div><p id="fu-message-send-limit">2 000 caractères · texte uniquement · 30 envois par heure maximum.</p>
            </form>
        <?php else: ?><p class="fu-message-readonly"><?php echo $row['blocked']?'Échange bloqué. Aucun nouvel envoi n’est possible.':($row['state']==='pending'?'En attente de l’acceptation du créateur.':'L’envoi est fermé pour cet échange.'); ?></p><?php endif;
    }
    /** @param array<string,mixed> $row */
    private static function decision(FansUiMessages $v,array $row,string $action,string $label): void
    {
        ?><form method="post" action="<?php echo esc_url($v->url(['thread'=>$row['thread_id']])); ?>"><?php self::fields('decision',['thread_id'=>$row['thread_id'],'revision'=>$row['revision'],'decision'=>$action]); ?><button type="submit"><?php echo esc_html($label); ?></button></form><?php
    }
    private static function privateList(FansUiMessages $v): void
    {
        $page=$v->listing?->get_data();
        if($v->listing?->get_status()!==200||!is_array($page)) {echo '<p role="alert">Liste indisponible.</p>';return;}
        if($page['items']===[]) {echo '<div class="fu-panel fu-message-empty"><h2>Aucun élément sur cette page</h2><p>Vos blocages ou vos dossiers apparaîtront ici lorsqu’ils existent.</p></div>';}
        foreach($page['items'] as $row): ?><article class="fu-panel fu-message-case">
            <?php if($v->section==='blocks'): ?><h2><?php echo esc_html($row['as_creator']?'Échange avec un Fan lié':FansUiMessages::creatorName($row['creator_id'])); ?></h2><p>Le blocage persiste même si les anciens messages ont expiré. Le lever n’accepte pas une demande refusée.</p>
                <form method="post" action="<?php echo esc_url($v->url(['section'=>'blocks'])); ?>"><?php self::fields('unblock',['block_id'=>$row['block_id'],'confirm'=>'yes']); ?><button type="submit">Lever mon blocage</button></form>
            <?php else: ?><p class="fu-panel__kicker">Dossier privé</p><h2><?php echo esc_html(FansUiMessages::state($row['state']==='open'?'open_report':$row['state'])); ?></h2><p>Ouvert le <?php echo esc_html($row['created_at'].' UTC'); ?>.</p>
                <p>Mesure : <?php echo esc_html(match($row['decision']){'no_action'=>'absence de mesure','remove'=>'message retiré','restrict'=>'échange restreint','restore'=>'restauration',default=>'en cours d’examen'}); ?>.</p>
                <a class="fu-link" href="<?php echo esc_url($v->url(['thread'=>$row['thread_id']])); ?>">Revenir à la conversation →</a>
                <?php if(in_array($row['state'],['decided','appealed'],true)): ?><form class="fu-message-form" method="post" action="<?php echo esc_url($v->url(['section'=>'reports'])); ?>">
                    <?php self::fields('appeal',['case_id'=>$row['case_id'],'revision'=>$row['revision']]); ?><label for="appeal-<?php echo esc_attr($row['case_id']); ?>">Motiver un recours</label><textarea id="appeal-<?php echo esc_attr($row['case_id']); ?>" name="reason" required maxlength="1000" rows="3"></textarea><p>Un recours par décision provisoire et par participant. Son texte est réservé aux modérateurs.</p><button type="submit">Transmettre mon recours</button>
                </form><?php endif; ?>
            <?php endif; ?>
        </article><?php endforeach;
        self::next($v,$page['next_cursor']);
    }
    /** @param array<string,string|int> $fields */
    private static function fields(string $action,array $fields): void
    {
        wp_nonce_field('fans_messages','fans_messages_nonce',false);
        foreach(['message_action'=>$action]+$fields as $name=>$value) {echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr((string)$value).'">';}
    }
    private static function next(FansUiMessages $v,?string $cursor): void
    {if($cursor!==null) {echo '<a class="fu-link" href="'.esc_url($v->url(['section'=>$v->section,'cursor'=>$cursor])).'">Page suivante →</a>';}}
}
