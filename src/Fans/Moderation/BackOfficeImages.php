<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Moderation;
use Faluss\Platform\Fans\Images\ImageService;
use Faluss\Platform\Fans\Images\ImagesModule;

final class BackOfficeImages
{
    public static function render(): void
    {
        if(!BackOffice::allowed()||!ImagesModule::available()){BackOfficeView::error('Images indisponibles.',403);return;}
        if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_POST['cleanup'])){
            BackOfficeOperations::guard(['cleanup','confirm','_wpnonce','_wp_http_referer']);
            if(ModerationPanel::field('cleanup',$_POST)!=='yes'){BackOfficeView::error('Opération invalide.',400);return;}
            if(!AdminActionLog::prepare()||($event=AdminActionLog::begin('images_cleanup'))===null){BackOfficeView::error('Journal indisponible.',503);return;}
            $result=ImageService::cleanup();$ok=!($result instanceof \WP_Error);$recorded=AdminActionLog::finish($event,$ok);
            if(!$ok||!$recorded){BackOfficeView::error('Nettoyage non confirmé. Vérifiez le stockage privé et le journal.',503);}
            else{echo '<p role="status">Nettoyage terminé : '.(int)$result['removed'].' fichier(s) retiré(s). Les images actives sont conservées.</p>';}
        }else{ModerationPanel::load();}
        ModerationPanel::render();
        echo '<form class="fb-card" method="post" action="'.esc_url(BackOffice::url(['view'=>'images'])).'">';wp_nonce_field('fans_backoffice');
        echo '<input type="hidden" name="cleanup" value="yes"><h2>Réconcilier le stockage privé</h2><p>Reprendre le nettoyage des fichiers révoqués ou orphelins. Les fichiers actifs ne sont pas supprimés.</p><p><label><input type="checkbox" name="confirm" value="yes" required> Je confirme le nettoyage.</label></p><button class="fb-button" type="submit">Exécuter le nettoyage</button></form>';
    }
}
