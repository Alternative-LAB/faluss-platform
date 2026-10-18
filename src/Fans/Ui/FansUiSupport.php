<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\PfContract\ClosedAttributionAttempt;
use Faluss\Platform\Fans\PfContract\ClosedAttributionAttemptSchema;
use Faluss\Platform\Fans\Profiles\EditorialService;
use Faluss\Platform\Fans\Moderation\ModerationPanel;
use Faluss\Platform\Fans\Sso\FansSsoService;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelValues;
use Faluss\Platform\TokenEngine\PurchasedPf\ModelViolation;
use Faluss\Platform\TokenEngine\PurchasedPf\Protocol\ClosedEnvironment;

/** Existing native components and protected POSTs; the physical recipe gate precedes server factory resolution. */
final class FansUiSupport
{
    public bool $available=false;
    public int $status=200;
    public string $id='';
    public string $name='';
    public string $quantity='1';
    public string $cursor='';
    public ?string $next=null;
    /** @var list<string> */
    public array $selected=[];
    /** @var list<array<string,string>> */
    public array $items=[];
    /** @var list<array<string,string>> */
    public array $retained=[];
    /** @var array<string,string>|null */
    public ?array $attempt=null;
    private bool $fresh=false;

    private function __construct(public readonly string $role,public readonly string $profile) {}

    public static function supports(string $view): bool { return $view==='hof' && array_key_exists('support',$_GET); }

    public static function provider(string $profile): ClosedAttributionAttempt
    {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) { throw new ModelViolation('pf_local_attempt_storage'); }
        ClosedEnvironment::assertIsolated($wpdb,'fans');ModelValues::uuid($profile);
        if (!ClosedAttributionAttemptSchema::ready($wpdb)) { throw new ModelViolation('pf_local_attempt_schema'); }
        $provider=apply_filters('faluss_fans_closed_attribution',null,$profile);
        if (!$provider instanceof ClosedAttributionAttempt || !$provider->usesConnection($wpdb)) { throw new ModelViolation('pf_local_attempt_unavailable'); }
        return $provider;
    }

    public static function link(string $role,string $profile): void
    {
        if (!in_array($role,['fan','creator'],true) || FansSsoService::currentLinkedSubject()===null) { return; }
        try { self::provider($profile)->choices($profile); }
        catch(ModelViolation) { return; }
        echo '<a class="fu-link fu-public-creator__action" href="'.esc_url(self::url($role,$profile)).'">Choisir les sessions de mon soutien ↗</a>';
    }

    public static function load(string $role): self
    {
        $profile=ModerationPanel::field('support',$_GET);$v=new self($role,$profile);
        $post=($_SERVER['REQUEST_METHOD']??'')==='POST';
        try {
            if (!in_array($role,['fan','creator'],true) || FansSsoService::currentLinkedSubject()===null) { throw new ModelViolation('pf_linked_session_required'); }
            $flow=self::provider($profile);$v->available=true;$v->id=wp_generate_uuid4();
            $public=EditorialService::publicById($profile);$v->name=$public['public_name']??'Créateur indisponible';
            if ($post) { $v->submit($flow); }
            $current=$flow->current();
            if ($current!==null && ((!$v->fresh && $current['creator_profile_id']===$profile) || !ClosedAttributionAttempt::terminal($current['phase']))) {
                $v->hydrate($flow,$current);
            } else {
                $page=$flow->choices($profile,$v->cursor);$v->items=$page['items'];$v->next=$page['next_cursor'];
                $shown=array_column($v->items,'session_id');
                $v->retained=$flow->selected($profile,array_values(array_diff($v->selected,$shown)));
            }
        } catch(ModelViolation $error) {
            $v->status=match($error->reason) {
                'pf_linked_session_required'=>403,'invalid_identity_or_reference','invalid_quantity_or_revision','pf_ranking_invalid_sessions','pf_ranking_duplicate_or_order','pf_invalid_operation'=>400,
                'pf_local_attempt_busy','pf_local_attempt_pending','pf_local_intent_conflict','pf_local_attempt_lookup_required'=>409,
                default=>503,
            };
            // Recover the existing checkpoint for display without sending, even when a submitted action was uncertain.
            if (isset($flow)) {
                try { $current=$flow->current();if ($current!==null) { $v->hydrate($flow,$current); } }
                catch(ModelViolation) { $v->available=false;$v->attempt=null; }
            }
        }
        return $v;
    }

    /** @param array<string,string> $row */
    private function hydrate(ClosedAttributionAttempt $flow,array $row): void
    {
        $intent=$flow->currentIntent();
        if ($intent===null || $intent->values['attribution_id']!==$row['attribution_id']) { throw new ModelViolation('pf_local_attempt_storage'); }
        $this->attempt=$row;$this->id=$row['attribution_id'];$this->quantity=$intent->values['purchased_pf'];
        $this->selected=array_column($intent->values['ranking_context']['sessions'],'session_id');
    }

    private function submit(ClosedAttributionAttempt $flow): void
    {
        if (wp_verify_nonce(ModerationPanel::field('fans_support_nonce',$_POST),'fans_support_'.$this->profile)===false) { throw new ModelViolation('pf_linked_session_required'); }
        if ($_FILES!==[] || array_diff(array_keys($_POST),['fans_support_nonce','support_action','attribution','quantity','sessions','choice_cursor'])!==[]) { throw new ModelViolation('pf_invalid_operation'); }
        $this->id=ModelValues::uuid(ModerationPanel::field('attribution',$_POST));
        $action=ModerationPanel::field('support_action',$_POST);
        if ($action==='new') {
            if (array_diff(array_keys($_POST),['fans_support_nonce','support_action','attribution'])!==[]) { throw new ModelViolation('pf_invalid_operation'); }
            $current=$flow->current();
            if ($current===null || $current['attribution_id']!==$this->id || !ClosedAttributionAttempt::terminal($current['phase'])) { throw new ModelViolation('pf_local_attempt_pending'); }
            $this->fresh=true;$this->id=wp_generate_uuid4();return;
        }
        if (in_array($action,['page','reserve'],true)) {
            $this->quantity=ModelValues::integer(ModerationPanel::field('quantity',$_POST),true);
            $raw=$_POST['sessions']??[];
            if (!is_array($raw) || !array_is_list($raw) || count($raw)>10) { throw new ModelViolation('pf_ranking_invalid_sessions'); }
            $this->selected=array_map(static fn(mixed $id):string=>ModelValues::uuid($id),$raw);
            if ($action==='page') {
                $this->cursor=ModerationPanel::field('choice_cursor',$_POST);
                if ($this->cursor!=='') { ModelValues::uuid($this->cursor); }
                $current=$flow->current();
                $this->fresh=$current===null || $current['attribution_id']!==$this->id;
                $flow->selected($this->profile,$this->selected);return;
            }
            $flow->start($this->id,$this->profile,$this->quantity,$this->selected);return;
        }
        if (array_diff(array_keys($_POST),['fans_support_nonce','support_action','attribution'])!==[]) { throw new ModelViolation('pf_invalid_operation'); }
        $flow->advance($this->id,$action);
    }

    public static function url(string $role,string $profile): string { return add_query_arg('support',$profile,FansUiRoutes::url($role,'hof')); }
}
