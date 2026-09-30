<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Ui;

/** Text-only private reading; labels never invent a Fan identity or reuse an account UUID. */
final class FansUiMessageView
{
    public static function render(FansUiMessages $v): void
    {
        if(!$v->available) {echo '<section class="fu-panel fu-message-empty"><h2>Messagerie indisponible</h2><p>Le service n’est pas ouvert sur cette installation. Aucun message ne peut être envoyé.</p></section>';return;}
        ?>
        <section class="fu-messages" data-fans-private-reading aria-label="Messagerie privée">
        <?php if(!\Faluss\Platform\Fans\Messaging\MessageModule::available()): ?><p class="fu-message-notice">Les nouveaux envois sont fermés. Vos conversations conservées, blocages, signalements et recours restent accessibles.</p><?php endif; ?>
        <nav class="fu-message-tabs" aria-label="Mes échanges">
            <?php foreach(['inbox'=>'Conversations','blocks'=>'Mes blocages','reports'=>'Signalements et recours'] as $key=>$label): ?>
            <a href="<?php echo esc_url($v->url(['section'=>$key])); ?>" <?php echo $v->section===$key?'aria-current="page"':''; ?>><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if($v->result!==null): ?><p class="fu-message-notice" role="<?php echo $v->result->get_status()>=400?'alert':'status'; ?>"><?php echo esc_html($v->notice()); ?></p><?php endif; ?>
        <?php if($v->section==='inbox'): ?>
        <div class="fu-message-layout">
            <aside class="fu-message-list fu-panel" aria-label="Mes conversations">
                <div class="fu-message-heading"><h2>Vos échanges</h2><a href="<?php echo esc_url($v->url()); ?>">Actualiser</a></div>
                <?php self::listing($v); ?>
                <a class="fu-link" href="<?php echo esc_url(FansUiRoutes::url($v->role,'explorer')); ?>">Trouver un créateur ↗</a>
            </aside>
            <div class="fu-message-chat fu-panel" id="fu-conversation">
                <?php if($v->threadId!==''||$v->creatorId!==''): ?><a class="fu-message-return" href="<?php echo esc_url($v->url()); ?>">← Vos échanges</a><?php endif; ?>
                <?php if($v->conversation!==null): self::conversation($v);
                elseif($v->creatorId!==''): self::request($v);
                else: ?><div class="fu-message-empty"><span aria-hidden="true">✉</span><h2>Un échange commence par une demande</h2><p>Choisissez une conversation ou ouvrez le profil d’un créateur pour lui écrire.</p><p>Votre demande reste textuelle. Le créateur l’accepte avant l’ouverture de la conversation.</p></div><?php endif; ?>
            </div>
        </div>
        <?php else: self::privateList($v); endif; ?>
        <details class="fu-message-policy"><summary>Demandes, confidentialité et conservation</summary>
            <p>Une demande : 1 000 caractères maximum. Une conversation acceptée : messages de 2 000 caractères maximum. Aucun média ni pièce jointe. Vous pouvez bloquer et signaler un message reçu.</p>
            <p>L’ouverture directe pour les abonnés Faluss Max ou au Créateur est indisponible ici : leur abonnement ne peut pas encore être vérifié. La demande suit donc le parcours d’acceptation.</p>
            <p>Les messages ordinaires expirent douze mois après le dernier envoi. Lire ou bloquer ne prolonge pas cette durée. Un signalement conserve séparément le message choisi et les éléments nécessaires au dossier, réservés aux modérateurs habilités.</p>
            <p>Ces preuves sont supprimées douze mois après la décision définitive, recours compris, sauf conservation de litige motivée et réexaminée. Les décisions et les recours sont accessibles dans « Signalements et recours ».</p>
        </details>
        </section>
        <?php
    }
    private static function listing(FansUiMessages $v): void
    {
        $page=$v->listing?->get_data();
        if($v->listing?->get_status()!==200||!is_array($page)) {echo '<p role="alert">Liste indisponible. Réessayez plus tard.</p>';return;}
        if($page['items']===[]) {echo '<p class="fu-footnote">Aucune conversation sur cette page.</p>';}
        foreach($page['items'] as $row):
            $name=$row['as_creator']?'Fan lié · nom public indisponible':FansUiMessages::creatorName($row['creator_id']); ?>
            <a class="fu-message-preview" href="<?php echo esc_url($v->url(['thread'=>$row['thread_id']])); ?>" <?php echo $row['thread_id']===$v->threadId?'aria-current="true"':''; ?>>
                <span class="fu-message-avatar" aria-hidden="true">◌</span><span><strong><?php echo esc_html($name); ?></strong><small><?php echo esc_html(FansUiMessages::state($row['state'])); ?></small><time><?php echo esc_html($row['last_sent_at'].' UTC'); ?></time></span>
            </a>
        <?php endforeach;
        self::next($v,$page['next_cursor']);
    }
    private static function request(FansUiMessages $v): void
    {
        if(!$v->recipientAvailable()) {echo '<div class="fu-message-empty"><h2>Destinataire indisponible</h2><p>Ce profil ne peut pas recevoir votre demande.</p></div>';return;}
        ?>
        <header class="fu-message-heading"><div><p class="fu-panel__kicker">Nouvelle demande</p><h2><?php echo esc_html(FansUiMessages::creatorName($v->creatorId)); ?></h2></div></header>
        <div class="fu-message-empty"><h3>Présentez votre demande</h3><p>Un seul texte est transmis. Attendez l’acceptation du créateur avant de poursuivre.</p><p>L’accès direct des abonnés est indisponible tant que leur abonnement ne peut pas être vérifié.</p></div>
        <form class="fu-message-form" method="post" action="<?php echo esc_url($v->url(['creator'=>$v->creatorId])); ?>">
            <?php self::fields('request',['creator_id'=>$v->creatorId,'key'=>$v->key]); ?>
            <label for="fu-message-body">Votre demande textuelle</label><textarea id="fu-message-body" name="body" maxlength="1000" required rows="5" aria-describedby="fu-message-request-limit"><?php echo esc_textarea($v->draft); ?></textarea>
            <p id="fu-message-request-limit">1 000 caractères maximum · aucun média · 2 nouvelles demandes par heure maximum.</p><button type="submit">Envoyer ma demande</button>
        </form>
        <?php
    }
    private static function conversation(FansUiMessages $v): void
    {
        $row=$v->conversation?->get_data();
        if($v->conversation?->get_status()!==200||!is_array($row)) {echo '<div class="fu-message-empty"><h2>Conversation indisponible</h2><p>Elle a pu expirer ou vous n’y avez plus accès. Aucun contenu privé n’est affiché.</p></div>';return;}
        $title=$row['as_creator']?'Fan lié · nom public indisponible':FansUiMessages::creatorName($row['creator_id']);
        ?>
        <header class="fu-message-heading"><span class="fu-message-avatar" aria-hidden="true">◌</span><div><h2><?php echo esc_html($title); ?></h2><p><?php echo esc_html(FansUiMessages::state($row['state'])); ?></p></div><a href="<?php echo esc_url($v->url(['thread'=>$row['thread_id']])); ?>">Actualiser</a></header>
        <?php if($row['blocked']): ?><p class="fu-message-notice">Échange bloqué. Aucun nouvel envoi n’est possible.</p><?php endif; ?>
        <div class="fu-message-actions">
            <?php if($row['as_creator']&&$row['state']==='pending'&&!$row['blocked']): self::decision($v,$row,'accept','Accepter la demande');self::decision($v,$row,'refuse','Refuser');endif;
            self::decision($v,$row,$row['blocked_by_me']?'unblock':'block',$row['blocked_by_me']?'Débloquer mon côté':'Bloquer cet échange'); ?>
        </div>
        <ol class="fu-message-log" aria-label="Messages conservés">
        <?php foreach($row['messages'] as $message): ?>
            <li class="fu-message-bubble<?php echo $message['mine']?' is-mine':''; ?>"><p class="fu-message-sender"><?php echo $message['mine']?'Vous':'Votre interlocuteur'; ?></p>
                <p class="fu-message-body"><?php echo esc_html($message['body']!==''?$message['body']:'Message retiré par la modération.'); ?></p><time><?php echo esc_html($message['sent_at'].' UTC'); ?></time>
                <?php if(!$message['mine']&&$message['body']!==''): ?><details><summary>Signaler ce message</summary><form method="post" action="<?php echo esc_url($v->url(['thread'=>$row['thread_id']])); ?>">
                    <?php self::fields('report',['thread_id'=>$row['thread_id'],'message_id'=>$message['message_id']]); ?>
                    <label for="report-<?php echo esc_attr($message['message_id']); ?>">Motif du signalement</label><select name="reason" id="report-<?php echo esc_attr($message['message_id']); ?>" required><option value="">Choisir un motif</option><option value="harassment">Harcèlement</option><option value="spam">Spam</option><option value="prohibited_content">Contenu interdit</option><option value="other">Autre motif</option></select>
                    <p>Seul ce message et son contexte minimal seront isolés pour la modération.</p><button type="submit">Confirmer le signalement</button>
                </form></details><?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ol>
        <?php if($row['next_after']!==null): ?><a class="fu-link" href="<?php echo esc_url($v->url(['thread'=>$row['thread_id'],'after'=>$row['next_after']])); ?>">Messages suivants →</a><?php endif; ?>
        <?php if($row['can_send']): ?>
            <form class="fu-message-form" method="post" action="<?php echo esc_url($v->url(['thread'=>$row['thread_id']])); ?>">
                <?php self::fields('send',['thread_id'=>$row['thread_id'],'key'=>$v->key]); ?>
                <label for="fu-message-body">Votre message</label><textarea name="body" id="fu-message-body" rows="3" maxlength="2000" required><?php echo esc_textarea($v->draft); ?></textarea><p>2 000 caractères maximum · texte uniquement · 30 envois par heure maximum.</p><button type="submit">Envoyer le message</button>
            </form>
        <?php else: ?><p class="fu-message-notice"><?php echo $row['state']==='pending'?'La conversation attend l’acceptation du créateur.':'L’envoi est fermé pour cet échange.'; ?></p><?php endif;
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
