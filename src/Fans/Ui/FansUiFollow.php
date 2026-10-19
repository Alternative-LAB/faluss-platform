<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Followers\{FollowersModule,FollowersService};
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Sso\FansSsoService;
use Faluss\Platform\Fans\Moderation\ModerationPanel;

/** Native free-follow action; the actual transition, not this form, selects the welcome event. */
final class FansUiFollow
{
    public static function handle(string $creator): void
    {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!array_key_exists('follow_action',$_POST)){return;}
        if($_FILES!==[]||array_diff(array_keys($_POST),['follow_action','fans_follow_nonce'])!==[]
            ||!in_array(ModerationPanel::field('follow_action',$_POST),['follow','unfollow'],true)) {
            wp_die('Formulaire indisponible.','',['response'=>400]);
        }
        if(wp_verify_nonce(ModerationPanel::field('fans_follow_nonce',$_POST),'fans_follow_'.$creator)===false) {
            wp_die('Rechargez la page avant de réessayer.','',['response'=>403]);
        }
        if(!FollowersModule::available()){wp_die('Le suivi est indisponible pour le moment.','',['response'=>503]);}
        $method=ModerationPanel::field('follow_action',$_POST)==='follow'?'POST':'DELETE';
        $result=ModerationPanel::request($method,'creators/'.$creator.'/follow',[]);
        if($result->get_status()!==200){wp_die('Le suivi n’a pas été confirmé. Réessayez plus tard.','',['response'=>$result->get_status()]);}
        wp_safe_redirect(home_url('/app/creators/'.$creator).'#fu-follow',303);exit;
    }

    public static function render(string $creator): void
    {
        if(!FollowersModule::available()||FansSsoService::currentLinkedSubject()===null
            ||CreatorProfileService::activeOwner($creator)===get_current_user_id()){return;}
        $state=FollowersService::ownFollowing($creator);
        if($state instanceof \WP_Error){echo '<p role="status">Le suivi est indisponible pour le moment.</p>';return;}
        ?>
        <form id="fu-follow" class="fu-follow" method="post" action="<?php echo esc_url(home_url('/app/creators/'.$creator)); ?>">
            <?php wp_nonce_field('fans_follow_'.$creator,'fans_follow_nonce',false); ?>
            <button type="submit" name="follow_action" value="<?php echo $state['following']?'unfollow':'follow'; ?>"><?php echo $state['following']?'Ne plus suivre':'Suivre'; ?></button>
        </form>
        <?php
    }
}
