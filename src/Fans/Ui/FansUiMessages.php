<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Ui;
use Faluss\Platform\Fans\Messaging\MessageModule;
use Faluss\Platform\Fans\Messaging\MessagePolicy;
use Faluss\Platform\Fans\Moderation\ModerationPanel;
use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\EditorialService;

/** Native forms dispatch through the same REST permissions and transactional services. */
final class FansUiMessages
{
    public bool $available=false;
    public string $role='fan';
    public string $section='inbox';
    public string $threadId='';
    public string $creatorId='';
    public ?\WP_REST_Response $result=null;
    public ?\WP_REST_Response $listing=null;
    public ?\WP_REST_Response $conversation=null;
    public ?\WP_REST_Response $contact=null;
    public ?\WP_REST_Response $automatic=null;
    public string $draft='';
    /** @var array{kind:string,body:string,enabled:bool,revision:int}|null */
    public ?array $automaticDraft=null;
    public string $key='';
    public static function load(string $role): self
    {
        $v=new self();$v->role=$role;
        if(!MessageModule::privateAccessAvailable()) {return $v;}
        $v->available=true;$v->key=wp_generate_uuid4();
        $v->section=ModerationPanel::field('section',$_GET)?:'inbox';
        if(!in_array($v->section,['inbox','blocks','reports','contact','automatic'],true)||(in_array($v->section,['contact','automatic'],true)&&$role!=='creator')) {$v->result=new \WP_REST_Response([],400);$v->section='inbox';}
        $v->threadId=ModerationPanel::field('thread',$_GET);$v->creatorId=ModerationPanel::field('creator',$_GET);
        foreach([$v->threadId,$v->creatorId] as $id) {if($id!==''&&!MessagePolicy::uuid($id)) {$v->result=new \WP_REST_Response([],400);$v->threadId=$v->creatorId='';}}
        if(($_SERVER['REQUEST_METHOD']??'')==='POST') {
            $v->result=self::submit();$data=$v->result->get_data();
            if(ModerationPanel::field('message_action',$_POST)==='automatic'
                &&($v->result->get_status()>=400||(is_array($data)&&($data['preview']??false)===true))) {
                $kind=ModerationPanel::field('kind',$_POST);$body=ModerationPanel::field('body',$_POST);
                $revision=ModerationPanel::field('revision',$_POST);$enabled=ModerationPanel::field('enabled',$_POST);
                if(in_array($kind,['welcome','thanks'],true)&&($body===''||MessagePolicy::text($body))
                    &&in_array($enabled,['yes','no'],true)&&preg_match('/^(0|[1-9][0-9]{0,8})$/D',$revision)===1) {
                    $v->automaticDraft=['kind'=>$kind,'body'=>$body,'enabled'=>$enabled==='yes','revision'=>(int)$revision];
                }
            }
            if($v->result->get_status()===200&&is_array($data)&&MessagePolicy::uuid($data['thread_id']??null)) {$v->threadId=$data['thread_id'];$v->creatorId='';}
            if($v->result->get_status()>=400&&in_array(ModerationPanel::field('message_action',$_POST),['request','send'],true)) {
                $draft=ModerationPanel::field('body',$_POST);$key=ModerationPanel::field('key',$_POST);
                if(MessagePolicy::text($draft)&&MessagePolicy::uuid($key)) {$v->draft=$draft;$v->key=$key;}
            }
        }
        if($v->section==='contact') {$v->contact=ModerationPanel::request('GET','messages/contact');return $v;}
        if($v->section==='automatic') {$v->automatic=ModerationPanel::request('GET','messages/automatic');return $v;}
        $route=match($v->section){'blocks'=>'messages/blocks','reports'=>'message-reports/mine',default=>'messages'};
        $v->listing=ModerationPanel::request('GET',$route,['cursor'=>ModerationPanel::field('cursor',$_GET)]);
        $case=ModerationPanel::field('case',$_GET);
        if($v->section==='reports'&&$case!==''){$row=\Faluss\Platform\Fans\Messaging\MessageReports::ownItem($case);$v->listing=new \WP_REST_Response(['items'=>is_array($row)?[$row]:[],'next_cursor'=>null],is_array($row)?200:404);}
        if($v->threadId!==''&&$v->section==='inbox') {
            $v->conversation=ModerationPanel::request('GET','messages/'.$v->threadId,['after'=>ModerationPanel::field('after',$_GET)?:'0']);
        }
        return $v;
    }
    private static function submit(): \WP_REST_Response
    {
        $field=static fn(string $k):string=>ModerationPanel::field($k,$_POST);
        if(wp_verify_nonce($field('fans_messages_nonce'),'fans_messages')===false) {return new \WP_REST_Response(['code'=>'invalid_nonce'],403);}
        $action=$field('message_action');
        $keys=match($action){'request'=>['creator_id','body','key'],'send'=>['thread_id','body','key'],'decision'=>['thread_id','revision','decision'],
            'report'=>['thread_id','message_id','reason'],'appeal'=>['case_id','revision','reason'],'unblock'=>['block_id','confirm'],'contact'=>['mode','revision'],
            'automatic'=>['kind','body','enabled','revision','intent'],default=>[]};
        if($keys===[]||$_FILES!==[]||array_diff(array_keys($_POST),array_merge($keys,['message_action','fans_messages_nonce']))!==[]
            ||array_diff($keys,array_keys($_POST))!==[]) {return new \WP_REST_Response(['code'=>'invalid_message_form'],400);}
        if(in_array($action,['send','decision','report'],true)&&!MessagePolicy::uuid($field('thread_id'))) {return new \WP_REST_Response([],400);}
        if(in_array($action,['decision','appeal'],true)&&preg_match('/^[1-9][0-9]{0,8}$/D',$field('revision'))!==1) {return new \WP_REST_Response([],400);}
        if($action==='appeal'&&!MessagePolicy::uuid($field('case_id'))) {return new \WP_REST_Response([],400);}
        if($action==='unblock'&&!MessagePolicy::uuid($field('block_id'))) {return new \WP_REST_Response([],400);}
        if($action==='contact'&&preg_match('/^(0|[1-9][0-9]{0,8})$/D',$field('revision'))!==1) {return new \WP_REST_Response([],400);}
        if($action==='automatic'&&(!in_array($field('kind'),['welcome','thanks'],true)||!in_array($field('enabled'),['yes','no'],true)
            ||!in_array($field('intent'),['save','preview'],true)||preg_match('/^(0|[1-9][0-9]{0,8})$/D',$field('revision'))!==1)) {return new \WP_REST_Response([],400);}
        [$path,$body]=match($action){
            'request'=>['messages/requests',['creator_id'=>$field('creator_id'),'body'=>$field('body'),'key'=>$field('key')]],
            'send'=>['messages/'.$field('thread_id').'/send',['body'=>$field('body'),'key'=>$field('key')]],
            'decision'=>['messages/'.$field('thread_id').'/decision',['revision'=>(int)$field('revision'),'action'=>$field('decision')]],
            'report'=>['messages/'.$field('thread_id').'/report',['message_id'=>$field('message_id'),'reason'=>$field('reason')]],
            'appeal'=>['message-reports/'.$field('case_id').'/appeal',['revision'=>(int)$field('revision'),'reason'=>$field('reason')]],
            'unblock'=>['messages/blocks/'.$field('block_id').'/unblock',['confirm'=>$field('confirm')==='yes']],
            'contact'=>['messages/contact',['mode'=>$field('mode'),'revision'=>(int)$field('revision')]],
            'automatic'=>['messages/automatic/'.$field('kind').($field('intent')==='preview'?'/preview':''),$field('intent')==='preview'
                ?['body'=>$field('body')]:['body'=>$field('body'),'enabled'=>$field('enabled')==='yes','revision'=>(int)$field('revision')]],
            default=>[null,[]],
        };
        if($path===null) {return new \WP_REST_Response([],400);}
        return ModerationPanel::request('POST',$path,$body);
    }
    public function httpStatus(): int
    {return max($this->result?->get_status()??200,$this->listing?->get_status()??200,$this->conversation?->get_status()??200,$this->contact?->get_status()??200,$this->automatic?->get_status()??200);}
    /** @param array<string,string|int> $query */
    public function url(array $query=[]): string {return add_query_arg($query,FansUiRoutes::url($this->role,'messages'));}
    /** @var array<string,array{name:string,portrait:string}> Request-local approved projections. */
    private array $correspondents=[];
    /** @return array{name:string,portrait:string} */
    public function correspondent(bool $asCreator,string $id): array
    {
        if($asCreator) {return ['name'=>'Membre Fans','portrait'=>''];}
        if(!isset($this->correspondents[$id])) {
            $public=EditorialService::publicById($id);
            $this->correspondents[$id]=['name'=>$public['public_name']??'Créateur',
                'portrait'=>!empty($public['portrait'])?rest_url('faluss-fans/v1/creators/'.$id.'/portrait/'.$public['revision']):''];
        }
        return $this->correspondents[$id];
    }
    public static function creatorName(string $id): string
    {return (string)(EditorialService::publicById($id)['public_name']??'Créateur · présentation indisponible');}
    public function recipientAvailable(): bool
    {return MessageModule::available() && $this->creatorId!=='' && CreatorProfileService::publicById($this->creatorId)!==null && CreatorProfileService::activeOwner($this->creatorId)!==get_current_user_id();}
    public static function state(string $state): string
    {return match($state){'automatic'=>'Message automatique','pending'=>'Demande en attente','open'=>'Conversation ouverte','refused'=>'Demande refusée','open_report'=>'Dossier ouvert','decided'=>'Décision provisoire','appealed'=>'Recours en cours','final'=>'Décision définitive',default=>'Échange restreint par la modération'};}
    public function notice(): string
    {
        $data=$this->result?->get_data();
        if($this->result?->get_status()===200&&is_array($data)&&($data['preview']??false)===true){return 'Aperçu uniquement : vos réglages ne sont pas modifiés.';}
        if($this->result?->get_status()===200) {return 'Action confirmée par le serveur. L’état courant est affiché ci-dessous.';}
        return match(is_array($data)?($data['code']??''):'') {
            'conversation_exists'=>'Une conversation existe déjà avec ce créateur. Retrouvez-la dans votre liste.',
            'conversation_blocked'=>'L’échange est bloqué. Aucun message n’a été envoyé.',
            'message_send_limit','message_request_limit','message_report_limit'=>'Limite atteinte. Réessayez plus tard ; vous pouvez toujours bloquer cet échange.',
            'conversation_conflict','report_conflict'=>'L’échange a changé. Relisez son état avant de confirmer à nouveau.',
            'message_contact_conflict'=>'Vos réglages ont changé dans une autre fenêtre. Relisez le mode courant avant de confirmer.',
            'message_automation_conflict'=>'Ce message automatique a été modifié dans une autre fenêtre. Relisez-le avant de confirmer.',
            'message_automatic_source_unavailable'=>'Les remerciements sont indisponibles pour le moment. Le message n’a pas été activé.',
            'conversation_expired'=>'Cette conversation a expiré. Ses messages ont été supprimés.',
            default=>'Action non confirmée. Vérifiez le formulaire et relisez l’état courant avant de réessayer.',
        };
    }
}
