<?php

declare(strict_types=1);

/*
 * @author  Moritz Vondano
 * @license MIT
 */

namespace Mvo\ContaoGroupWidget\Test\Group;

use Doctrine\DBAL\Connection;
use Mvo\ContaoGroupWidget\EventListener\GroupWidgetListener;
use Mvo\ContaoGroupWidget\Group\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class GroupTest extends TestCase
{
    /**
     * @dataProvider provideDefinitions
     */
    public function testCreatesGroup(array $definition, \Closure $assertionCallback): void
    {
        $definition['inputType'] = 'group';

        $GLOBALS['TL_DCA']['tl_foo']['fields'] = [
            'my_group' => $definition,
            'foo' => [
                'inputType' => 'text',
                'eval' => ['tl_class' => 'w50', 'mandatory' => true],
            ],
        ];

        $group = new Group(
            $this->createMock(ContainerInterface::class),
            'tl_foo',
            123,
            'my_group'
        );

        $assertionCallback($group);

        unset($GLOBALS['TL_DCA']);
    }

    public function provideDefinitions(): \Generator
    {
        yield 'defaults' => [
            [
                'palette' => ['foo'],
            ],
            static function (Group $group): void {
                self::assertEquals('my_group', $group->getName());
                self::assertEquals('tl_foo', $group->getTable());
                self::assertEquals(123, $group->getRowId());
                self::assertEquals('', $group->getLabel());
                self::assertEquals('', $group->getDescription());
                self::assertEquals(0, $group->getMinElements());
                self::assertEquals(0, $group->getMaxElements());
                self::assertEquals(['foo'], $group->getFields());
            },
        ];

        yield 'label/description' => [
            [
                'label' => ['my group', 'pretty nice'],
                'palette' => ['foo'],
            ],
            static function (Group $group): void {
                self::assertEquals('my group', $group->getLabel());
                self::assertEquals('pretty nice', $group->getDescription());
            },
        ];

        yield 'min/max' => [
            [
                'min' => 2,
                'max' => 10,
                'palette' => ['foo'],
            ],
            static function (Group $group): void {
                self::assertEquals(2, $group->getMinElements());
                self::assertEquals(10, $group->getMaxElements());
            },
        ];

        yield 'implicit palette' => [
            [
                'fields' => [
                    'bar' => [
                        'inputType' => 'text',
                    ],
                ],
            ],
            static function (Group $group): void {
                self::assertEquals(['bar'], $group->getFields());
            },
        ];

        yield 'fields and palette' => [
            [
                'palette' => ['foo', 'bar'],
                'fields' => [
                    'bar' => [
                        'inputType' => 'text',
                    ],
                ],
            ],
            static function (Group $group): void {
                self::assertEquals(['foo', 'bar'], $group->getFields());
            },
        ];

        yield 'merged fields' => [
            [
                'fields' => [
                    'foo' => [
                        'eval' => ['mandatory' => false],
                    ],
                ],
            ],
            static function (Group $group): void {
                self::assertEquals(['foo'], $group->getFields());
                self::assertEquals([
                    'inputType' => 'text',
                    'eval' => [
                        'tl_class' => 'w50',
                        'mandatory' => false,
                    ],
                ], $group->getFieldDefinition('foo'));
            },
        ];
    }

    /**
     * @dataProvider provideInvalidDefinitions
     */
    public function testThrowsWithInvalidDefinition(array $definition, string $exception): void
    {
        $definition['inputType'] = 'group';

        $GLOBALS['TL_DCA']['tl_foo']['fields'] = [
            'my_group' => $definition,
            'foo' => [
                'inputType' => 'text',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($exception);

        new Group(
            $this->createMock(ContainerInterface::class),
            'tl_foo',
            123,
            'my_group'
        );

        unset($GLOBALS['TL_DCA']);
    }

    public function provideInvalidDefinitions(): \Generator
    {
        yield 'no fields/palette' => [
            [],
            "Invalid definition for group 'my_group': Keys 'palette' and 'fields' cannot both be empty.",
        ];

        yield 'empty palette' => [
            [
                'palette' => [],
            ],
            "Invalid definition for group 'my_group': Keys 'palette' and 'fields' cannot both be empty.",
        ];

        yield 'bad field reference' => [
            [
                'palette' => ['foo', 'bar'],
            ],
            "Invalid definition for group 'my_group': Field 'bar' does not exist.",
        ];

        yield 'min out of range' => [
            [
                'palette' => ['foo'],
                'min' => -10,
            ],
            "Invalid definition for group 'my_group': Key 'min' cannot be less than 0.",
        ];

        yield 'max smaller than min' => [
            [
                'palette' => ['foo'],
                'min' => 4,
                'max' => 3,
            ],
            "Invalid definition for group 'my_group': Key 'max' cannot be less than 'min'.",
        ];

        yield 'bad storage engine' => [
            [
                'palette' => ['foo'],
                'storage' => 'bookshelf',
            ],
            "Invalid definition for group 'my_group': Unknown storage type 'bookshelf'.",
        ];
    }

    public function testExpandsPalette(): void
    {
        $GLOBALS['TL_DCA']['tl_foo']['palettes']['default'] = '{some_legend},foobar;my_group';

        $GLOBALS['TL_DCA']['tl_foo']['fields'] = [
            'my_group' => [
                'inputType' => 'group',
                'palette' => ['foo', 'bar'],
                'fields' => [
                    'bar' => [
                        'inputType' => 'random',
                    ],
                ],
                'min' => 2,
            ],
            'foo' => [
                'inputType' => 'text',
            ],
        ];

        $connection = $this->createMock(Connection::class);

        $connection
            ->method('quoteIdentifier')
            ->willReturn('')
        ;

        $connection
            ->method('fetchOne')
            ->willReturn(null)
        ;

        $locator = $this->createMock(ContainerInterface::class);
        $locator
            ->method('get')
            ->with('database_connection')
            ->willReturn($connection)
        ;

        $group = new Group(
            $locator,
            'tl_foo',
            123,
            'my_group'
        );

        $group->expand('default');

        $expectedFooDefinition = [
            'inputType' => 'text',
            'label' => null,
            'eval' => [
                'doNotSaveEmpty' => true,
            ],
            'load_callback' => [[GroupWidgetListener::class, 'onLoadGroupField']],
            'save_callback' => [[GroupWidgetListener::class, 'onStoreGroupField']],
            'sql' => null,
        ];

        $expectedBarDefinition = [
            'inputType' => 'random',
            'label' => null,
            'eval' => [
                'doNotSaveEmpty' => true,
            ],
            'load_callback' => [[GroupWidgetListener::class, 'onLoadGroupField']],
            'save_callback' => [[GroupWidgetListener::class, 'onStoreGroupField']],
            'sql' => null,
        ];

        $expectedFields = [
            'my_group__foo__1' => $expectedFooDefinition,
            'my_group__bar__1' => $expectedBarDefinition,
            'my_group__foo__2' => $expectedFooDefinition,
            'my_group__bar__2' => $expectedBarDefinition,
        ];

        $expectedGroupDelimiterFields = [
            'my_group__(start)',
            'my_group__(el_start)__1',
            'my_group__(el_end)__1',
            'my_group__(el_start)__2',
            'my_group__(el_end)__2',
            'my_group__(end)',
        ];

        foreach ($expectedFields as $field => $definition) {
            self::assertArrayHasKey($field, $GLOBALS['TL_DCA']['tl_foo']['fields']);
            self::assertSame($definition, $GLOBALS['TL_DCA']['tl_foo']['fields'][$field]);
        }

        foreach ($expectedGroupDelimiterFields as $field) {
            self::assertArrayHasKey($field, $GLOBALS['TL_DCA']['tl_foo']['fields']);
            self::assertInstanceOf(
                \Closure::class,
                $GLOBALS['TL_DCA']['tl_foo']['fields'][$field]['input_field_callback']
            );
        }

        $expectedPalette = '{some_legend},foobar;'.
            'my_group__(start),'.
            'my_group__(el_start)__1,my_group__foo__1,my_group__bar__1,my_group__(el_end)__1,'.
            'my_group__(el_start)__2,my_group__foo__2,my_group__bar__2,my_group__(el_end)__2,'.
            'my_group__(end)';

        self::assertEquals($expectedPalette, $GLOBALS['TL_DCA']['tl_foo']['palettes']['default']);

        unset($GLOBALS['TL_DCA']);
    }
}
