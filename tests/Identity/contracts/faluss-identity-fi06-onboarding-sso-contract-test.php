<?php

define( 'ABSPATH', __DIR__ . '/' );

function home_url( $path = '/' ) { return 'https://faluss.me' . ( '/' === $path ? '/' : '/' . ltrim( $path, '/' ) ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

require_once dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-onboarding.php';

function fi06_onboarding_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

function fi06_onboarding_private( $method, ...$arguments ) {
    $reflection = new ReflectionMethod( 'Faluss_Identity_Onboarding', $method );
    $reflection->setAccessible( true );
    return $reflection->invoke( null, ...$arguments );
}

// Completion is the explicit ONB-01 choice plus final step. A published card
// is validated when the state is written but cannot itself bypass this gate.
fi06_onboarding_assert( true === fi06_onboarding_private( 'requires_onboarding', true, array( 'choice' => 'unknown', 'next_step' => 'choice' ) ), 'A profile without an explicit ONB-01 completion stays in onboarding.' );
fi06_onboarding_assert( true === fi06_onboarding_private( 'requires_onboarding', false, array( 'choice' => 'create_card', 'next_step' => 'wizard_header' ) ), 'An unfinished card wizard cannot authorize SSO.' );
fi06_onboarding_assert( false === fi06_onboarding_private( 'requires_onboarding', false, array( 'choice' => 'create_card', 'next_step' => 'complete' ) ), 'Publication finalizes the canonical onboarding state.' );
fi06_onboarding_assert( false === fi06_onboarding_private( 'requires_onboarding', false, array( 'choice' => 'no_card', 'next_step' => 'complete' ) ), 'The explicit no-card choice finalizes the canonical onboarding state.' );

$root = dirname( __DIR__, 3 );
$onboarding = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-onboarding.php' );
$authorization = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-authorization.php' );
$passwordless = file_get_contents( $root . '/src/Identity/Legacy/includes/class-faluss-identity-passwordless.php' );
$link = file_get_contents( $root . '/src/Link/LegacyLinkService.php' );

foreach ( array( 'current_member_has_completed_onboarding', "'complete' !== ( \$state['next_step'] ?? '' )", 'onboarding_completion_destination', 'pending_onboarding_resume_url', "'no_card' === \$choice ? self::onboarding_completion_destination()" ) as $needle ) {
    fi06_onboarding_assert( false !== strpos( $onboarding, $needle ), 'The explicit onboarding completion and local continuation are missing: ' . $needle );
}
foreach ( array( 'const ONBOARDING_REQUEST_TTL = 3600', 'authorization_deferred_for_onboarding', 'defer_for_onboarding', 'member_has_completed_onboarding', "request_from_cookie( array( 'pending', 'onboarding' ) )", "complete_authorization( \$request, \$faluss_id, true, array( 'pending', 'onboarding' ) )", "return home_url( '/oauth/authorize/' );" ) as $needle ) {
    fi06_onboarding_assert( false !== strpos( $authorization, $needle ), 'The server-side first-party onboarding continuation is missing: ' . $needle );
}
$resume_start = strpos( $authorization, 'private static function resume_or_login' );
$resume_end = strpos( $authorization, 'private static function handle_consent', $resume_start );
$resume = false === $resume_start || false === $resume_end ? '' : substr( $authorization, $resume_start, $resume_end - $resume_start );
fi06_onboarding_assert( false !== strpos( $resume, 'defer_for_onboarding' ) && strpos( $resume, 'defer_for_onboarding' ) < strpos( $resume, 'complete_authorization' ), 'An incomplete first-party member is deferred before any authorization code can be issued.' );
fi06_onboarding_assert( false !== strpos( $link, 'LinkIdentityAdapter::onboardingCompletionDestination()' ), 'Card publication returns through the shared Identity contract continuation.' );
fi06_onboarding_assert( false !== strpos( $passwordless, 'is_authorization_return' ) && false !== strpos( $passwordless, 'Authorization then applies Faluss.me' ), 'Passwordless resumes only the local authorization route, whose server gate owns onboarding.' );
fi06_onboarding_assert( false === strpos( $onboarding, 'data-faluss-id=' ) && false === strpos( $onboarding, 'name="faluss_id"' ), 'The onboarding browser never receives a Faluss ID.' );
fi06_onboarding_assert( false === strpos( $onboarding, 'redirect_uri' ) && false === strpos( $onboarding, 'pkce_challenge' ), 'The onboarding browser never receives OAuth callback or PKCE data.' );

echo 'FI-06 onboarding SSO continuation contract: OK' . PHP_EOL;
