<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Ui;

/** Creator-only configuration. Preview is escaped text, never a message to a Fan. */
final class FansUiAutomaticMessages
{
    public static function render(FansUiMessages $v): void
    {
        ?><header class="fu-message-heading"><h2>Messages automatiques</h2></header><div class="fu-message-scroll fu-message-automatic"><?php
        $data=$v->automatic?->get_data();
        if($v->automatic?->get_status()!==200||!is_array($data)){echo '<p role="alert">Ces réglages sont indisponibles pour le moment.</p></div>';return;}
        $preview=$v->result?->get_data();
        foreach(['welcome'=>'Bienvenue','thanks'=>'Remerciement'] as $kind=>$title):
            $row=$data[$kind];
            // Keep the submitted revision after a conflict; never silently overwrite a newer edit.
            if(($v->automaticDraft['kind']??'')===$kind){$row=$v->automaticDraft;}
            $body=$row['body'];
            $hasPreview=is_array($preview)&&($preview['preview']??false)===true&&($preview['kind']??'')===$kind;
            if($hasPreview){$body=$preview['body'];}
        ?>
        <section class="fu-auto-setting" aria-labelledby="fu-auto-<?php echo esc_attr($kind); ?>">
            <h3 id="fu-auto-<?php echo esc_attr($kind); ?>"><?php echo esc_html($title); ?></h3>
            <p><?php echo $kind==='welcome'?'Un seul message au premier suivi d’un Fan.':'Un message pour chaque soutien admissible confirmé.'; ?></p>
            <?php if($kind==='thanks'&&!($data['thanks_delivery_available']??false)): ?><p>Les remerciements sont indisponibles pour le moment. Vous pouvez préparer votre texte.</p><?php endif; ?>
            <form method="post" action="<?php echo esc_url($v->url(['section'=>'automatic'])); ?>">
                <?php wp_nonce_field('fans_messages','fans_messages_nonce',false); ?>
                <input type="hidden" name="message_action" value="automatic"><input type="hidden" name="kind" value="<?php echo esc_attr($kind); ?>"><input type="hidden" name="revision" value="<?php echo (int)$row['revision']; ?>">
                <label for="fu-auto-state-<?php echo esc_attr($kind); ?>">Envoi automatique</label>
                <select name="enabled" id="fu-auto-state-<?php echo esc_attr($kind); ?>"><option value="no" <?php echo !$row['enabled']?'selected':''; ?>>Désactivé</option><option value="yes" <?php echo $row['enabled']?'selected':''; ?> <?php echo $kind==='thanks'&&!($data['thanks_delivery_available']??false)?'disabled':''; ?>>Activé</option></select>
                <label for="fu-auto-body-<?php echo esc_attr($kind); ?>">Votre texte</label>
                <textarea name="body" id="fu-auto-body-<?php echo esc_attr($kind); ?>" rows="5" maxlength="2000" aria-describedby="fu-auto-limit-<?php echo esc_attr($kind); ?>"><?php echo esc_textarea($body); ?></textarea>
                <p id="fu-auto-limit-<?php echo esc_attr($kind); ?>">2 000 caractères maximum. Les règles de contenu et de modération de Fans s’appliquent.</p>
                <?php if($kind==='welcome'): ?><p>Le contenu joint est indisponible pour le moment. Votre message reste textuel.</p><?php endif; ?>
                <div class="fu-auto-actions"><button type="submit" name="intent" value="preview">Aperçu</button><button type="submit" name="intent" value="save">Enregistrer <?php echo esc_html(mb_strtolower($title)); ?></button></div>
            </form>
            <details <?php echo $hasPreview?'open':''; ?>><summary>Aperçu du message<?php echo $hasPreview?' non enregistré':''; ?></summary><p class="fu-auto-preview"><?php echo esc_html($body!==''?$body:'Ajoutez votre texte pour le prévisualiser.'); ?></p></details>
        </section>
        <?php endforeach; ?></div><?php
    }
}
