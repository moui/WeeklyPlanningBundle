<?php

declare(strict_types=1);

/*
 * This file is part of the "WeeklyPlanningBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WeeklyPlanningBundle\DependencyInjection;

use App\Plugin\AbstractPluginExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class WeeklyPlanningExtension extends AbstractPluginExtension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        // Planning shows and edits other people's weeks: team leads and admins
        // only. Which people and projects each of them gets is decided per
        // request by ScopeResolver, from Kimai's team memberships.
        $container->prependExtensionConfig('kimai', [
            'permissions' => [
                'roles' => [
                    'ROLE_TEAMLEAD' => ['weekly_planning'],
                    'ROLE_ADMIN' => ['weekly_planning'],
                    'ROLE_SUPER_ADMIN' => ['weekly_planning'],
                ],
            ],
        ]);

        // Explicit mapping rather than relying on auto_mapping guessing the
        // driver: the entities use PHP attributes.
        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'entity_managers' => [
                    'default' => [
                        'mappings' => [
                            'WeeklyPlanningBundle' => [
                                'is_bundle' => false,
                                'type' => 'attribute',
                                'dir' => __DIR__ . '/../Entity',
                                'prefix' => 'KimaiPlugin\WeeklyPlanningBundle\Entity',
                                'alias' => 'WeeklyPlanning',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function getAlias(): string
    {
        return 'weekly_planning';
    }
}
