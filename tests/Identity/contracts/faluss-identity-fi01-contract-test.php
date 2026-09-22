<?php

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-schema.php';
require_once dirname( __DIR__, 3 ) . '/src/Identity/Legacy/includes/class-faluss-identity-registry.php';

function fi01_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}

final class FI01_Failing_Preparation_Wpdb {

    public $prefix = 'wp_';
    public $queries = array();

    public function get_charset_collate() {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    public function query( $query ) {
        $this->queries[] = $query;
        return false;
    }
}

$schema = Faluss_Identity_Schema::get_expected_schema();
$fi01_schema = Faluss_Identity_Schema::get_fi01_schema();
fi01_assert( 'varchar(255)' === $schema['challenges']['columns']['otp_hash']['type'], 'FI-02 keeps the widened OTP hash.' );
fi01_assert( isset( $schema['challenges']['columns']['email'], $schema['challenges']['columns']['email_hash'] ), 'FI-02 challenge fields are present.' );
fi01_assert( '6' === Faluss_Identity_Schema::VERSION, 'A new installation targets the additive FI-06 SSO schema.' );
fi01_assert( 6 === count( $fi01_schema ), 'FI-01 defines exactly six Identity tables.' );
fi01_assert( isset( $schema['public_profiles'] ), 'FI-03 adds the isolated public-profile table.' );
fi01_assert( isset( $schema['authorization_requests'] ), 'FI-04 adds the server-side authorization-request ledger.' );
fi01_assert( isset( $schema['clients']['columns']['first_party'] ), 'FI-06 SSO adds an explicit first-party client marker.' );

foreach ( array( 'profiles', 'challenges', 'rate_limits', 'clients', 'auth_codes', 'audit' ) as $table ) {
    fi01_assert( isset( $schema[ $table ] ), 'Missing ' . $table . ' table definition.' );
    fi01_assert( isset( $schema[ $table ]['indexes']['PRIMARY'] ), 'Missing ' . $table . ' primary key.' );
}

fi01_assert( $fi01_schema['profiles']['indexes']['faluss_id_unique']['unique'], 'faluss_id must be unique.' );
fi01_assert( $fi01_schema['profiles']['indexes']['wp_user_id_unique']['unique'], 'wp_user_id must be unique.' );
fi01_assert( array( 'bucket_type', 'bucket_hash' ) === $fi01_schema['rate_limits']['indexes']['bucket_type_hash_unique']['columns'], 'Rate-limit unique index order changed.' );
fi01_assert( array( 'client_id', 'expires_at' ) === $fi01_schema['auth_codes']['indexes']['client_expires_at']['columns'], 'Auth-code index order changed.' );
fi01_assert( $schema['public_profiles']['indexes']['faluss_id_unique']['unique'] && $schema['public_profiles']['indexes']['public_slug_unique']['unique'], 'Public profiles are uniquely tied to a Faluss ID and slug.' );
fi01_assert( ! isset( $schema['public_profiles']['columns']['wp_user_id'] ), 'Public-profile data has no local WordPress-user key.' );
fi01_assert( Faluss_Identity_Registry::is_valid_faluss_id( '550e8400-e29b-41d4-a716-446655440000' ), 'A UUID v4 must be accepted.' );
fi01_assert( ! Faluss_Identity_Registry::is_valid_faluss_id( '550e8400-e29b-11d4-a716-446655440000' ), 'A non-v4 UUID must be refused.' );

$wpdb = new FI01_Failing_Preparation_Wpdb();
$wpdb->prefix = str_repeat( 'x', 21 );
fi01_assert( null === Faluss_Identity_Schema::get_install_plan( '0123456789abcdef' ), 'An overlong temporary table name must fail before preparation.' );
$wpdb->prefix = 'wp_';
$plan = Faluss_Identity_Schema::get_install_plan( '0123456789abcdef' );
fi01_assert( is_array( $plan ), 'Atomic install plan must be generated.' );
fi01_assert( 8 === count( $plan['temporary_tables'] ), 'Atomic install needs all FI-04 tables.' );
foreach ( $plan['temporary_tables'] as $temporary_table ) {
    fi01_assert( strlen( $temporary_table ) <= 64, 'Temporary table name must stay within MySQL limits.' );
}

$queries = Faluss_Identity_Schema::get_install_queries( $plan, $wpdb->get_charset_collate() );
fi01_assert( is_array( $queries ) && 8 === count( $queries['temporary_creates'] ), 'All FI-04 temporary CREATE statements are planned.' );
foreach ( $queries['temporary_creates'] as $key => $query ) {
    fi01_assert( false !== strpos( $query, chr( 96 ) . $plan['temporary_tables'][ $key ] . chr( 96 ) ), 'Preparation must target its temporary table.' );
    foreach ( $plan['final_tables'] as $final_table ) {
        fi01_assert( false === strpos( $query, chr( 96 ) . $final_table . chr( 96 ) ), 'Preparation must not write a final table.' );
    }
}
fi01_assert( 0 === strpos( $queries['promotion'], 'RENAME TABLE ' ), 'Promotion must use one grouped RENAME TABLE statement.' );
fi01_assert( 8 === substr_count( $queries['promotion'], ' TO ' ), 'Promotion must include all FI-04 table renames.' );

$prepare = new ReflectionMethod( 'Faluss_Identity_Schema', 'prepare_temporary_tables' );
$prepare->setAccessible( true );
fi01_assert( false === $prepare->invoke( null, $plan ), 'A temporary preparation failure must stop the installation.' );
fi01_assert( 1 === count( $wpdb->queries ), 'Preparation failure must stop before another table is attempted.' );
fi01_assert( false !== strpos( $wpdb->queries[0], chr( 96 ) . $plan['temporary_tables']['profiles'] . chr( 96 ) ), 'Failed preparation must only attempt a temporary table.' );
foreach ( $plan['final_tables'] as $final_table ) {
    fi01_assert( false === strpos( $wpdb->queries[0], chr( 96 ) . $final_table . chr( 96 ) ), 'Failed preparation must not create a final table.' );
}
fi01_assert( false === strpos( $wpdb->queries[0], 'RENAME TABLE ' ), 'Failed preparation must not promote a table.' );

echo 'FI-01 schema contract: OK' . PHP_EOL;
