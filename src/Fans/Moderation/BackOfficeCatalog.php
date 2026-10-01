<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Moderation;
use Faluss\Platform\Fans\Store\StoreCatalogModule;
use Faluss\Platform\Fans\Store\StoreCatalogService;
use Faluss\Platform\Fans\Store\PurchaseGate;
use Faluss\Platform\Fans\Profiles\EditorialService;

final class BackOfficeCatalog
{
    public static function render(): void
    {
        if(!BackOffice::allowed()||!StoreCatalogModule::available()){BackOfficeView::error('Catalogue indisponible.',403);return;}
        echo '<h1>Catalogue administratif</h1><p>Registre technique existant. Aucun achat, prix, réservation ou droit de livraison n’est ouvert.</p>';
        $key=wp_generate_uuid4();
        if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
            BackOfficeOperations::guard(['creator','creation_key','confirm','_wpnonce','_wp_http_referer']);
            $key=ModerationPanel::field('creation_key',$_POST);$creator=ModerationPanel::field('creator',$_POST);
            if(!EditorialService::validId($key)||!EditorialService::validId($creator)){BackOfficeView::error('Référence invalide.',400);return;}
            if(!AdminActionLog::prepare()||($event=AdminActionLog::begin('catalog_create'))===null){BackOfficeView::error('Journal indisponible.',503);return;}
            $result=StoreCatalogService::create($creator,PurchaseGate::HOSTED,$key);
            $ok=!($result instanceof \WP_Error);$recorded=AdminActionLog::finish($event,$ok);
            if(!$ok||!$recorded){BackOfficeView::error('Création non confirmée. Réessayez avec la même clé et le même créateur.', $result instanceof \WP_Error?(int)($result->get_error_data()['status']??503):503);}
            else{echo '<p role="status">Entrée enregistrée par le service catalogue. Les achats restent refusés.</p>';$key=wp_generate_uuid4();}
        }
        $state=ModerationPanel::field('state',$_GET)?:'current';$cursor=ModerationPanel::field('cursor',$_GET);
        echo '<nav class="fb-pagination"><a href="'.esc_url(BackOffice::url(['view'=>'catalog','state'=>'current'])).'">Catalogue courant</a><a href="'.esc_url(BackOffice::url(['view'=>'catalog','state'=>'archive'])).'">Archives</a></nav>';
        $item=ModerationPanel::field('item',$_GET);
        $page=StoreCatalogService::administrationList($cursor,$state);
        if($item!==''){$row=StoreCatalogService::administrationItem($item);if($row===null){BackOfficeView::error('Entrée indisponible.',404);return;}$page=['items'=>[$row],'next_cursor'=>null];}
        if($page instanceof \WP_Error){BackOfficeView::error('Catalogue indisponible.',(int)($page->get_error_data()['status']??503));return;}
        echo '<div class="fb-grid">';
        foreach($page['items'] as $row){echo '<article class="fb-card faluss-moderation"><h2>'.esc_html($row['category_label']).'</h2><p>'.esc_html($row['archived']?'Archivé':$row['visibility']).' · '.esc_html($row['created_at']).' UTC</p>';AccountContext::creator($row['creator_id']);echo '<p class="fb-meta">Référence privée : '.esc_html($row['product_id']).'</p></article>';}
        echo '</div>';if($page['items']===[]){echo '<p class="fb-empty">Aucune entrée dans cet état.</p>';}
        if($page['next_cursor']!==null){echo '<p><a href="'.esc_url(BackOffice::url(['view'=>'catalog','state'=>$state,'cursor'=>$page['next_cursor']])).'">Page suivante →</a></p>';}
        ?>
        <form class="fb-card fb-search" method="post" action="<?php echo esc_url(BackOffice::url(['view'=>'catalog'])); ?>">
            <?php wp_nonce_field('fans_backoffice'); ?><input type="hidden" name="creation_key" value="<?php echo esc_attr($key); ?>">
            <label for="fb-catalog-creator">Référence du profil Créateur actif</label><input id="fb-catalog-creator" name="creator" value="<?php echo esc_attr(ModerationPanel::field('creator',$_POST)); ?>" required>
            <label><input type="checkbox" name="confirm" value="yes" required> Créer une entrée administrative de contenu hébergé ; aucun achat ne devient disponible.</label>
            <button type="submit">Enregistrer dans le catalogue</button>
        </form>
        <?php
    }
}
