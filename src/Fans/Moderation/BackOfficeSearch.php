<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Moderation;
use Faluss\Platform\Fans\Images\ImageService;

final class BackOfficeSearch
{
    public static function reference(string $id): void
    {
        if (!BackOffice::allowed()) { return; }
        echo '<h2>Objets à cette référence</h2><div class="fb-grid">'; $found = false;
        foreach (['profiles'=>['creators/'.$id.'/private','Profil Créateur'], 'editorial'=>['editorial/'.$id.'/private','Présentation'],
            'texts'=>['text-publications/'.$id.'/private','Publication'], 'messages'=>['message-reports/'.$id,'Dossier privé']] as $view=>[$route,$label]) {
            if (!isset(BackOffice::sections()[$view])) { continue; }
            $response = ModerationPanel::request('GET',$route);
            if ($response->get_status() !== 200) { continue; }
            $found = true; self::link($view,$id,$label);
        }
        if (isset(BackOffice::sections()['images']) && ImageService::administrationItem($id) !== null) { $found = true; self::link('images',$id,'Image privée'); }
        if (isset(BackOffice::sections()['catalog']) && \Faluss\Platform\Fans\Store\StoreCatalogService::administrationItem($id) !== null) { $found = true; self::link('catalog',$id,'Entrée du catalogue'); }
        echo '</div>'; if (!$found) { echo '<p class="fb-empty">Aucun objet accessible à cette référence.</p>'; }
    }
    private static function link(string $view,string $id,string $label): void
    { echo '<article class="fb-card"><h3>'.esc_html($label).'</h3><a href="'.esc_url(BackOffice::url(['view'=>$view,'item'=>$id])).'">Examiner →</a></article>'; }
}
