<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Package;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Fabricated features for packages the base fixtures already imported, so the feature blocks of
 * the package page can be seen in dev — no real package declares any yet.
 */
class FeatureFixtures extends Fixture implements FixtureGroupInterface
{
    /**
     * ramsey/uuid is the showcase: its features are its real `suggest` entries rewritten as
     * resolvable groups, which is the migration the feature system is meant to enable. psr/log and
     * monolog/monolog cover the consuming side, monolog requiring a feature of a real dependency.
     */
    private const FEATURES = [
        'ramsey/uuid' => [
            'features' => [
                'bcmath' => [
                    'description' => 'Faster math with arbitrary-precision integers using BCMath',
                    'require' => ['ext-bcmath' => '*'],
                ],
                'gmp' => [
                    'description' => 'Faster math with arbitrary-precision integers using GMP',
                    'require' => ['ext-gmp' => '*'],
                ],
                'pecl-uuid' => [
                    'description' => 'PeclUuidTimeGenerator and PeclUuidRandomGenerator, backed by the PECL uuid extension',
                    'require' => ['ext-uuid' => '*'],
                ],
                'random-lib' => [
                    'description' => 'RandomLibAdapter, generating UUIDs with RandomLib',
                    'require' => ['paragonie/random-lib' => '^2.0'],
                ],
                'doctrine' => [
                    'description' => 'Use Ramsey\\Uuid\\Uuid as a Doctrine field type',
                    'require' => ['ramsey/uuid-doctrine' => '^2.0'],
                ],
            ],
        ],
        'psr/log' => [
            'features' => [
                'test-utils' => [
                    'description' => 'Test doubles asserting what a library logs',
                    'require' => ['phpunit/phpunit' => '^11.0'],
                ],
            ],
        ],
        'monolog/monolog' => [
            'require-features' => ['psr/log' => ['test-utils']],
        ],
    ];

    public static function getGroups(): array
    {
        return ['features'];
    }

    public function load(ObjectManager $manager): void
    {
        foreach (self::FEATURES as $name => $data) {
            $package = $manager->getRepository(Package::class)->findOneBy(['name' => $name]);
            if (null === $package) {
                echo 'Skipping '.$name.', it is not in the database — run the base fixtures first.'.PHP_EOL;
                continue;
            }

            foreach ($package->getVersions() as $version) {
                $version->setFeatures($data['features'] ?? null);
                $version->setRequireFeatures($data['require-features'] ?? null);
            }

            echo 'Added features to '.\count($package->getVersions()).' versions of '.$name.'.'.PHP_EOL;
        }

        $manager->flush();
    }
}
