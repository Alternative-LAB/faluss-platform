<?php
declare(strict_types=1);
// Local WordPress fixture only; no remote data or production configuration.
use Faluss\Platform\Fans\Profiles\{CreatorStatusSchema,CreatorProfileService,EditorialService};
use Faluss\Platform\Fans\Messaging\{MessageSchema,ReportSchema};
use Faluss\Platform\Fans\Images\ImageSchema;
if(!defined('WP_CLI')||!WP_CLI||DB_NAME!=='admission_recipe'){throw new RuntimeException('Disposable fixture only');}
$s=json_decode(file_get_contents($args[0]),true);
foreach([CreatorStatusSchema::installOrVerify(),MessageSchema::installOrVerify(),ReportSchema::installOrVerify(),ImageSchema::installOrVerify()] as $ok){if(!$ok){throw new RuntimeException('Fixture schema failure');}}
$check=static function($value){if(is_wp_error($value)){throw new RuntimeException($value->get_error_code());}return $value;};
wp_set_current_user($s['sessions']['admin']['id']);
$check(CreatorProfileService::setStatus($s['profiles']['other'],'active'));
get_user_by('id',$s['sessions']['admin-two']['id'])->add_cap('moderate_faluss_fans_messages');
wp_set_current_user($s['sessions']['other']['id']);
$edit=$check(EditorialService::submit(0,'Atelier des formes','Compte synthétique pour la recette de messagerie.','',0));
wp_set_current_user($s['sessions']['admin']['id']);
$check(EditorialService::decide($s['profiles']['other'],$edit['revision'],'approve','allowed_editorial'));
// Geometric approved portrait fixture; no fabricated person or borrowed artwork.
$im=imagecreatetruecolor(320,320);
imagefill($im,0,0,imagecolorallocate($im,28,56,47));
imagefilledellipse($im,210,115,210,210,imagecolorallocate($im,194,166,116));
imagefilledrectangle($im,25,190,230,305,imagecolorallocate($im,68,155,124));
imagejpeg($im,dirname($args[0]).'/fixture.jpg',90);
echo "Local schemas, approved synthetic creator and image fixture ready.\n";
