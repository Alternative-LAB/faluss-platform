<?php
namespace Faluss\Platform\Fans\Images;
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['image_options'][$key] ?? $default; }
