<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/Profiles/recipe/bootstrap.php';
$fixtureUser=0;
$result=\Faluss\Platform\Fans\Messaging\MessageAutomaticDelivery::run();
if($result instanceof WP_Error){echo $result->get_error_code();exit(1);}
echo json_encode($result);
