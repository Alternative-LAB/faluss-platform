<?php

declare(strict_types=1);

namespace Faluss\Platform\Identity;

use PHPUnit\Framework\TestCase;

final class IdentityCharacterizationTest extends TestCase
{
    public function testHistoricalSourcesAndAssetsRemainByteIdentical(): void
    {
        $root = dirname(__DIR__, 2);
        $legacy = $root . '/src/Identity/Legacy/includes/';
        $files = [
            'class-faluss-identity-admin-diagnostic.php' => 'daf9a4df9ff17ca375ed95e4ae59d81b7447dbf5a3702880e77e2c6fdac64a64',
            'class-faluss-identity-authorization.php' => '060d359e485dc6003883373ae466369e1278af34df289a5a8577350523540a32',
            'class-faluss-identity-elementor-widget.php' => 'f7f8236e85f2e0bdae94d919106098d39548fa0b97854ee8894ffc092dd0df62',
            'class-faluss-identity-front-preferences.php' => '292eb4c3da88a5486618da45126658d48cd3e7693fb2c15c68feaaad06ffc38b',
            'class-faluss-identity-member-session.php' => '4947ea1a8b243f38fd96377cccef0f1275873dca547f2822b5bd6fdb63a5571d',
            'class-faluss-identity-navigation-elementor-widget.php' => 'c91ab42ee4b6244e542aaf6b999275334f443db32e81cbc8791158bcf49e17bd',
            'class-faluss-identity-navigation.php' => 'a28c6b56e7d7093164da35fc1b5aa1e1941d41535e8b490d4a876860c0b8f9ae',
            'class-faluss-identity-onboarding-elementor-widget.php' => 'e22059042b83a245154b784570db3a1c05a4e4907d75f40da7a65d99a51c32af',
            'class-faluss-identity-onboarding.php' => '6def5ef0869af03d5de2b62c027b06ff3e95550d708a280aaac26e88c5c47c84',
            'class-faluss-identity-passwordless.php' => '11e87b26f3f12712f77fd77d5d19609560f7dcfb240037cd2b0f449430719f30',
            'class-faluss-identity-plugin.php' => '8af13fc3000fed7972d0d848f067fcdb31d8efaf2103be951c3ffe24b44e00a3',
            'class-faluss-identity-public-profile-elementor-widgets.php' => 'e1d3a988e5c36d87e6dfaf6072a8da3341cb9880b053951d3271e63af69df5a1',
            'class-faluss-identity-public-profile.php' => 'd226486df21f4d6007e21ff9fd103d30fec92670ba6192cc0eb12e5ac8b1a43e',
            'class-faluss-identity-registry.php' => 'c2b3e52f91e0a7855aeb4dd399c6b5bde5fca46a777f66eab632346d3dad72c6',
            'class-faluss-identity-schema.php' => '3141ead5dbcaef6baeda9ae04991130e87ca3076436c94b038c829e312891220',
            'class-faluss-identity-sso-clients-admin.php' => '56fa014d8904f097e1519c3a4f8a03bb33f1f23a864b08cf6eb0628eb41fd496',
        ];
        $assets = [
            'assets/css/faluss-identity-authorization.css' => '9dc325ecb768e5cb615bb7a2db12f27764b19a1cc66fd064d02458122a69efd8',
            'assets/css/faluss-identity-navigation.css' => 'b0ee91dbd0bb8b01b6713e546ecdf73684e31b83dc64aa7c86d3443db5597dd3',
            'assets/css/faluss-identity-onboarding.css' => '8f099388b9a249d0890c0b8411e98b56a0dc407c33f852f5838beace6d371f41',
            'assets/css/faluss-identity-passwordless.css' => '98f17deca03474c710b674435d0d8d42e802b88be6c1c817396df6dc2b6bff7e',
            'assets/css/faluss-identity-public-profile.css' => '01689634cde6285987c1e53a5d24b9b7310c15b8ed5ddbf4ed3bfca50e45fb7a',
            'assets/js/faluss-identity-navigation.js' => '520cf6e32a0d2200e2d7e262fb62de6ec2efea496d908d8993abfd41e6d5c58a',
            'assets/js/faluss-identity-onboarding.js' => '486b6fa938508393564e19bd0ba7adba970d96f6fed6125f7e1c76ab7f256b42',
            'assets/js/faluss-identity-passwordless-login.js' => 'f6fe696ae0eadd4bd20eaadb5b43adbc4d73619ad65d73a27df7c6034c101199',
        ];

        foreach ($files as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', $legacy . $file), $file);
        }
        foreach ($assets as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', $root . '/' . $file), $file);
        }
    }
}
