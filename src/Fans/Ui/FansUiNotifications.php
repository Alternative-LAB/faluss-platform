<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Ui;
use Faluss\Platform\Fans\Notifications\NotificationService;
use Faluss\Platform\Fans\Moderation\ModerationPanel;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Publications\TextPublicationService;
use Faluss\Platform\Fans\Images\ImageService;
use Faluss\Platform\Fans\Messaging\MessageReading;

final class FansUiNotifications
{
    /** @var array<string,mixed>|\WP_Error */
    public array|\WP_Error $page;
    public int $status=200;
    public string $filter='all';
    public bool $changed=false;
    public ?string $destination=null;
    public string $cursor='';
    public static function load(string $role='fan'): self
    {
        $v=new self();$field=static fn(string $key,array $data):string=>ModerationPanel::field($key,$data);
        if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
            if(wp_verify_nonce($field('fans_notifications_nonce',$_POST),'fans_notifications')===false){$v->status=403;}
            elseif($field('action',$_POST)==='open' && $_FILES===[] && array_diff(array_keys($_POST),['fans_notifications_nonce','notification','action'])===[]){
                $result=self::open($field('notification',$_POST),$role);
                if($result instanceof \WP_Error){$v->status=(int)($result->get_error_data()['status']??503);}else{$v->destination=$result;}
            }
            elseif($_FILES!==[]||array_diff(array_keys($_POST),['fans_notifications_nonce','notification','unread'])!==[]||!in_array($field('unread',$_POST),['0','1'],true)){$v->status=400;}
            else{$result=NotificationService::mark($field('notification',$_POST),$field('unread',$_POST)==='1');$v->status=$result instanceof \WP_Error?(int)($result->get_error_data()['status']??503):200;$v->changed=$result===true;}
        }
        $v->filter=$field('filter',$_GET)?:'all';$v->cursor=$field('cursor',$_GET);$v->page=NotificationService::listing($v->cursor,$v->filter);
        if($v->page instanceof \WP_Error){$v->status=(int)($v->page->get_error_data()['status']??503);}return $v;
    }
    /** Recheck the owned notification and current target rights on POST; never accept a destination from the browser. */
    public static function open(string $id,string $role): string|\WP_Error
    {
        $row=NotificationService::item($id);if($row instanceof \WP_Error){return $row;}
        $link=self::link($row,$role);if($link===null){return NotificationService::error(409);}
        $result=NotificationService::mark($id,false);return $result instanceof \WP_Error?$result:$link;
    }
    public static function bell(string $role): void
    {
        if(!in_array($role,['fan','creator'],true)){return;}$count=NotificationService::count();
        echo '<div class="fu-notifications-bar" data-notifications-endpoint="'.esc_url(rest_url('faluss-fans/v1/notification-view')).'" data-notifications-nonce="'.esc_attr(wp_create_nonce('wp_rest')).'" data-role="'.esc_attr($role).'"><a class="fu-link fu-bell" href="'.esc_url(FansUiRoutes::url($role,'notifications')).'">';
        echo '<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M5 17h14l-2-3V9a5 5 0 0 0-10 0v5l-2 3ZM10 20h4"/></svg><span>Notifications</span>';
        echo $count===null?'<span class="fu-footnote">Indisponibles</span>':'<span class="fu-notification-count" aria-label="'.(int)$count.' non lues">'.(int)$count.'</span>';echo '</a>';
        \Faluss\Platform\Fans\Sso\FansLocalSession::renderControl();
        echo '</div>';
    }
    /** @param array<string,mixed> $row */
    public static function link(array $row,string $role): ?string
    {
        $id=$row['object_id'];$kind=$row['kind'];
        if(str_starts_with($kind,'report_')){return is_array(\Faluss\Platform\Fans\Messaging\MessageReports::ownItem($id))?add_query_arg(['section'=>'reports','case'=>$id],FansUiRoutes::url($role,'messages')):null;}
        if(str_starts_with($kind,'message_')){return MessageReading::notificationAccessible($id)?add_query_arg('thread',$id,FansUiRoutes::url($role,'messages')):null;}
        $own=CreatorProfileService::own();if($own===null){return null;}
        if(str_starts_with($kind,'profile_')){return $own['creator_id']===$id?FansUiRoutes::url('fan','espace'):null;}
        if(str_starts_with($kind,'editorial_')){return $own['creator_id']===$id?FansUiRoutes::url('creator','mon-profil'):null;}
        if(str_starts_with($kind,'publication_')){$item=TextPublicationService::get($id,true);return is_array($item)&&$item['creator_id']===$own['creator_id']?add_query_arg(['publication'=>$id,'archive'=>in_array($item['state'],['rejected','withdrawn'],true)?'1':'0'],FansUiRoutes::url('creator','creer')):null;}
        if(str_starts_with($kind,'image_')){return ImageService::ownItem($id)!==null?add_query_arg('image',$id,FansUiRoutes::url('creator','images')).'#fu-images':null;}
        return null;
    }
    public static function label(string $kind): string
    {
        if($kind==='report_decision'){return 'Une décision est disponible pour un dossier vous concernant';}
        if($kind==='report_final'){return 'Un dossier vous concernant a été finalisé';}
        return match($kind){'profile_active'=>'Votre profil Créateur est actif','profile_suspended'=>'Votre profil Créateur est suspendu','editorial_approved'=>'Votre présentation est approuvée','editorial_rejected'=>'Votre présentation a été refusée','editorial_revoked'=>'Votre présentation publique a été retirée','publication_approved'=>'Votre publication est approuvée','publication_rejected'=>'Votre publication a été refusée','image_approved'=>'Votre image est approuvée','image_rejected'=>'Votre image a été refusée','message_request'=>'Nouvelle demande de message','message_received'=>'Nouveau message','message_accepted'=>'Votre demande de message est acceptée','message_refused'=>'Votre demande de message a été refusée',default=>'Événement indisponible'};
    }
    public static function reason(string $reason): string
    { return match($reason){'needs_revision'=>'Des modifications sont nécessaires avant approbation.','prohibited_content'=>'Le contenu ne respecte pas les règles de publication.',default=>''}; }
    public static function render(self $v,string $role): void
    {
        $url=FansUiRoutes::url($role,'notifications');
        echo '<section class="fu-content fu-notifications" data-filter="'.esc_attr($v->filter).'" data-cursor="'.esc_attr($v->cursor).'">';
        echo '<p class="fu-notification-status" role="status">'.($v->status>=400?'Ouverture ou lecture indisponible. Réessayez.':($v->changed?'État de lecture enregistré.':'')).'</p>';
        if($v->page instanceof \WP_Error){echo '</section>';return;}
        echo '<nav aria-label="Filtrer les notifications"><a class="fu-link" '.($v->filter==='all'?'aria-current="page" ':'').'href="'.esc_url($url).'">Toutes</a><a class="fu-link" '.($v->filter==='unread'?'aria-current="page" ':'').'href="'.esc_url(add_query_arg('filter','unread',$url)).'">Non lues (<span data-unread-count>'.(int)$v->page['unread'].'</span>)</a></nav>';
        echo '<div class="fu-notification-list">';self::rows($v->page['items'],$role);echo '</div>';
        echo '<nav class="fu-notification-pagination" aria-label="Pagination des notifications">';self::pagination($v->filter,$v->page['next_cursor'],$role);echo '</nav></section>';
    }
    /** @param list<array<string,mixed>> $items */
    public static function rows(array $items,string $role): void
    {
        if($items===[]){echo '<p class="fu-notification-empty">Aucune notification dans cette vue.</p>';}
        foreach($items as $row){
            $unread=(bool)$row['unread'];$link=self::link($row,$role);$reason=self::reason($row['reason']);
            echo '<div class="fu-notification" data-id="'.(int)$row['id'].'" data-unread="'.($unread?'true':'false').'">';
            if($link!==null){echo '<form method="post" action="'.esc_url(FansUiRoutes::url($role,'notifications')).'">'.wp_nonce_field('fans_notifications','fans_notifications_nonce',false,false).'<input type="hidden" name="notification" value="'.(int)$row['id'].'"><input type="hidden" name="action" value="open"><button class="fu-notification-row" type="submit">';}
            else{echo '<div class="fu-notification-row fu-notification-unavailable">';}
            echo '<span class="fu-notification-copy"><span class="fu-notification-label">'.esc_html(self::label($row['kind'])).'</span><span class="fu-sr-only">'.($unread?'Non lue.':'Lue.').'</span>';
            if($reason!==''){echo '<span class="fu-notification-reason">'.esc_html($reason).'</span>';}
            if($link===null){echo '<span class="fu-notification-reason">Objet indisponible</span>';}
            echo '</span><time datetime="'.esc_attr(str_replace(' ','T',$row['created_at']).'Z').'" title="'.esc_attr($row['created_at'].' UTC').'">'.esc_html(gmdate('d/m · H:i',(int)strtotime($row['created_at'].' UTC'))).'</time>';
            echo $link!==null?'<span class="fu-notification-arrow" aria-hidden="true">↗</span></button></form></div>':'</div></div>';
        }
    }
    public static function pagination(string $filter,?string $next,string $role): void
    {
        $url=FansUiRoutes::url($role,'notifications');
        echo '<a class="fu-link" href="'.esc_url(add_query_arg('filter',$filter,$url)).'">Revenir au début</a>';
        if($next!==null){echo '<a class="fu-link" href="'.esc_url(add_query_arg(['cursor'=>$next,'filter'=>$filter],$url)).'">Page suivante →</a>';}
    }
}
